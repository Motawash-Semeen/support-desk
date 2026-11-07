<?php

namespace App\Actions\Imports;

use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketImport;
use App\Models\TicketImportError;
use App\Models\User;
use App\Services\Sla\SlaCalculator;
use App\Services\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Imports ONE chunk of CSV rows (default 500):
 *
 *  1. validate every row (Validator::make), collecting failures;
 *  2. batch lookups for the whole chunk: users by email, categories by name or
 *     slug, already-imported references (whereIn), plus duplicates inside the chunk;
 *  3. reserve the ticket numbers for the accepted rows in ONE call;
 *  4. compute SLA per row (pure CPU: the rules are cached);
 *  5. ONE transaction: create missing customers, bulk insertOrIgnore tickets,
 *     bulk insert `imported` activities and row errors, update the counters and
 *     the checkpoint (last_processed_row).
 *
 * Bulk inserts bypass model events on purpose: no TicketCreated, no emails,
 * no auto-assignment for imported tickets. The unique (source,
 * external_reference) index is the guarantee against duplicates; the
 * pre-check is only an optimisation.
 */
final class ImportTicketRows
{
    public const REQUIRED_COLUMNS = ['external_reference', 'customer_email', 'subject', 'description', 'priority', 'category'];

    public const OPTIONAL_COLUMNS = ['status', 'assigned_agent_email', 'created_at'];

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly TicketNumberGenerator $numbers,
        private readonly SlaCalculator $sla,
    ) {}

    /**
     * @param  Collection<int, array{row: int, data: array<string, string>|null, raw: list<string|null>}>  $rows
     * @param  int  $processedThrough  data rows processed once this chunk commits (the checkpoint)
     * @return array{success: int, failed: int}
     */
    public function __invoke(TicketImport $import, Collection $rows, int $processedThrough): array
    {
        $now = CarbonImmutable::now();
        $errors = [];
        $candidates = [];

        // 1. Shape and field validation.
        foreach ($rows as $row) {
            if ($row['data'] === null) {
                $errors[] = $this->error($row['row'], $row['raw'], ['row' => ['The row has a different number of columns than the header.']]);

                continue;
            }

            $data = $this->normalise($row['data']);
            $validator = Validator::make($data, $this->rules());

            if ($validator->fails()) {
                $errors[] = $this->error($row['row'], $row['data'], $validator->errors()->toArray());

                continue;
            }

            $candidates[] = ['row' => $row['row'], 'data' => $data];
        }

        // 2. Batch lookups for the whole chunk (no per-row queries).
        $emails = collect($candidates)
            ->flatMap(fn (array $c) => array_filter([$c['data']['customer_email'], $c['data']['assigned_agent_email']]))
            ->unique()->values()->all();
        $users = User::query()->whereIn('email', $emails)->get(['id', 'email', 'role'])->keyBy(fn (User $u) => strtolower($u->email));

        $categoryValues = collect($candidates)->pluck('data.category')->unique()->values();
        $categories = Category::query()
            ->whereIn('name', $categoryValues->all())
            ->orWhereIn('slug', $categoryValues->map(fn ($v) => Str::slug($v))->all())
            ->get(['id', 'name', 'slug']);
        $categoryByName = $categories->keyBy(fn (Category $c) => mb_strtolower($c->name));
        $categoryBySlug = $categories->keyBy('slug');

        $existingRefs = Ticket::query()
            ->where('source', TicketSource::Import)
            ->whereIn('external_reference', collect($candidates)->pluck('data.external_reference')->all())
            ->pluck('external_reference')
            ->flip();

        // 3. Row-level checks that need the lookups.
        $accepted = [];
        $seen = [];
        $newCustomers = [];

        foreach ($candidates as $candidate) {
            ['row' => $line, 'data' => $data] = $candidate;
            $ref = $data['external_reference'];

            if (isset($seen[$ref])) {
                $errors[] = $this->error($line, $data, ['external_reference' => ["Duplicate external_reference in this file (first seen on row {$seen[$ref]})."]]);

                continue;
            }

            if (isset($existingRefs[$ref])) {
                $errors[] = $this->error($line, $data, ['external_reference' => ['Duplicate: a ticket with this external_reference was already imported.']]);

                continue;
            }

            $category = $categoryByName[mb_strtolower($data['category'])] ?? $categoryBySlug[Str::slug($data['category'])] ?? null;
            if ($category === null) {
                $errors[] = $this->error($line, $data, ['category' => ["Unknown category \"{$data['category']}\"."]]);

                continue;
            }

            $agentId = null;
            if ($data['assigned_agent_email'] !== '') {
                $agent = $users[$data['assigned_agent_email']] ?? null;

                if ($agent === null || $agent->role !== Role::Agent) {
                    $errors[] = $this->error($line, $data, ['assigned_agent_email' => ['No agent account has this email.']]);

                    continue;
                }

                $agentId = $agent->id;
            }

            $customer = $users[$data['customer_email']] ?? null;
            if ($customer !== null && $customer->role !== Role::Customer) {
                $errors[] = $this->error($line, $data, ['customer_email' => ['This email belongs to a staff account.']]);

                continue;
            }

            if ($customer === null) {
                $newCustomers[$data['customer_email']] = true;
            }

            $seen[$ref] = $line;
            $accepted[] = ['row' => $line, 'data' => $data, 'category_id' => $category->id, 'agent_id' => $agentId];
        }

        // 5. One transaction for the whole chunk, ending with the checkpoint.
        $success = DB::transaction(function () use ($import, $accepted, $newCustomers, $users, $now, $processedThrough, &$errors): int {
            $customerIds = $users->filter(fn (User $u) => $u->role === Role::Customer)->map->id->all();
            $customerIds += $this->createCustomers(array_keys($newCustomers), $now);

            // 3. One reservation per chunk; rolled back with the chunk if anything fails.
            $numbers = $accepted === [] ? [] : $this->numbers->reserve(count($accepted), $now);

            $tickets = [];
            foreach ($accepted as $i => $row) {
                $tickets[] = $this->ticketRow($row, $numbers[$i], $customerIds[$row['data']['customer_email']], $now);
            }

            if ($tickets !== []) {
                Ticket::query()->insertOrIgnore($tickets);
            }

            // Rows the unique index rejected (e.g. a parallel import) are reported, not lost.
            $inserted = $numbers === [] ? collect() : Ticket::query()
                ->whereIn('ticket_number', $numbers)
                ->get(['id', 'ticket_number'])
                ->keyBy('ticket_number');

            $activities = [];
            foreach ($accepted as $i => $row) {
                $ticket = $inserted[$numbers[$i]] ?? null;

                if ($ticket === null) {
                    $errors[] = $this->error($row['row'], $row['data'], ['external_reference' => ['Duplicate: a ticket with this external_reference was already imported.']]);

                    continue;
                }

                $activities[] = [
                    'ticket_id' => $ticket->id,
                    'actor_id' => $import->user_id,
                    'type' => ActivityType::Imported->value,
                    'description' => Str::limit("Imported from {$import->original_name} (row {$row['row']}, reference {$row['data']['external_reference']})", 250),
                    'old_values' => null,
                    'new_values' => json_encode(['import_id' => $import->id, 'row' => $row['row'], 'external_reference' => $row['data']['external_reference']]),
                    'created_at' => $now->format(self::DATE_FORMAT),
                ];
            }

            if ($activities !== []) {
                TicketActivity::query()->insert($activities);
            }

            if ($errors !== []) {
                TicketImportError::query()->insert(array_map(fn (array $e) => $e + [
                    'ticket_import_id' => $import->id,
                    'created_at' => $now->format(self::DATE_FORMAT),
                ], $errors));
            }

            $success = count($activities);

            TicketImport::query()->whereKey($import->id)->update([
                'processed_rows' => DB::raw('processed_rows + '.(int) ($success + count($errors))),
                'success_rows' => DB::raw('success_rows + '.(int) $success),
                'failed_rows' => DB::raw('failed_rows + '.(int) count($errors)),
                'last_processed_row' => $processedThrough,
                'updated_at' => $now->format(self::DATE_FORMAT),
            ]);

            return $success;
        });

        if (config('support.import.welcome_new_customers')) {
            foreach (array_keys($newCustomers) as $email) {
                Password::sendResetLink(['email' => $email]);
            }
        }

        return ['success' => $success, 'failed' => count($errors)];
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'external_reference' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'priority' => ['required', Rule::in(array_column(TicketPriority::cases(), 'value'))],
            'category' => ['required', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_column(TicketStatus::cases(), 'value'))],
            'assigned_agent_email' => ['nullable', 'email', 'max:255'],
            'created_at' => ['nullable', 'date'],
        ];
    }

    /** @param array<string, string> $data */
    private function normalise(array $data): array
    {
        $value = fn (string $key) => trim((string) ($data[$key] ?? ''));

        return [
            'external_reference' => $value('external_reference'),
            'customer_email' => strtolower($value('customer_email')),
            'subject' => $value('subject'),
            'description' => $value('description'),
            'priority' => strtolower($value('priority')),
            'category' => $value('category'),
            'status' => strtolower($value('status')),
            'assigned_agent_email' => strtolower($value('assigned_agent_email')),
            'created_at' => $value('created_at'),
        ];
    }

    /**
     * Bulk-creates customers that do not exist yet. They get one random,
     * never-revealed password hash per chunk (nobody knows it: they log in
     * through a password reset), no verified email, and no welcome email
     * unless support.import.welcome_new_customers is set.
     *
     * @param  list<string>  $emails
     * @return array<string, int> email => id
     */
    private function createCustomers(array $emails, CarbonImmutable $now): array
    {
        if ($emails === []) {
            return [];
        }

        $password = Hash::make(Str::random(64));
        $timestamp = $now->format(self::DATE_FORMAT);

        User::query()->insertOrIgnore(array_map(fn (string $email) => [
            'name' => Str::headline(Str::before($email, '@')),
            'email' => $email,
            'password' => $password,
            'role' => Role::Customer->value,
            'is_available' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $emails));

        return User::query()->whereIn('email', $emails)->pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [strtolower($email) => (int) $id])->all();
    }

    /**
     * @param  array{row: int, data: array<string, string>, category_id: int, agent_id: ?int}  $row
     * @return array<string, mixed>
     */
    private function ticketRow(array $row, string $number, int $customerId, CarbonImmutable $now): array
    {
        $data = $row['data'];
        $createdAt = $data['created_at'] === '' ? $now : CarbonImmutable::parse($data['created_at'])->utc();
        $priority = TicketPriority::from($data['priority']);
        $status = $data['status'] === '' ? TicketStatus::Open : TicketStatus::from($data['status']);
        $sla = $this->sla->calculate($priority, $createdAt);
        $active = $status->isActive();
        $format = fn (?CarbonImmutable $at) => $at?->utc()->format(self::DATE_FORMAT);

        return [
            'ticket_number' => $number,
            'subject' => $data['subject'],
            'description' => $data['description'],
            'priority' => $priority->value,
            'status' => $status->value,
            'category_id' => $row['category_id'],
            'created_by' => $customerId,
            'assigned_agent_id' => $row['agent_id'],
            'assigned_at' => $row['agent_id'] === null ? null : $format($createdAt),
            'sla_policy_id' => $sla->policyId,
            'sla_minutes' => $sla->minutes,
            'sla_warning_at' => $format($sla->warningAt),
            'due_at' => $format($sla->dueAt),
            'first_response_at' => null,
            // The file has only one timestamp; finished tickets are dated by it,
            // so imports neither inflate "resolved today" nor fake breaches.
            'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? $format($createdAt) : null,
            'closed_at' => $status === TicketStatus::Closed ? $format($createdAt) : null,
            'is_escalated' => false,
            'escalation_level' => 0,
            // Historical active tickets whose milestones already passed are marked
            // as notified, so sla:scan sends no retroactive storm of warnings.
            'warning_notified_at' => $active && $sla->warningAt->lte($now) ? $format($now) : null,
            'breached_notified_at' => $active && $sla->dueAt->lt($now) ? $format($now) : null,
            'external_reference' => $data['external_reference'],
            'source' => TicketSource::Import->value,
            'created_at' => $format($createdAt),
            'updated_at' => $format($now),
        ];
    }

    /** @return array{row_number: int, raw_data: string, errors: string} */
    private function error(int $line, array $data, array $messages): array
    {
        return [
            'row_number' => $line,
            'raw_data' => json_encode($data),
            'errors' => json_encode($messages),
        ];
    }
}
