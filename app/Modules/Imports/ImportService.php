<?php

namespace App\Modules\Imports;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\TicketImport;
use App\Modules\Imports\Models\TicketImportError;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketSource;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\TicketService;
use App\Support\SafeFilename;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Admin CSV import:
 *  1. start(): store the file, record a pending import, queue the job;
 *  2. run(): stream the file in chunks; each chunk saves a checkpoint, so a retry resumes;
 *  3. importRows(): validate a chunk, look everything up at once, write it in one transaction.
 * Imported tickets send no notifications and are not auto-assigned. The unique
 * (source, external_reference) index prevents duplicates.
 */
class ImportService
{
    public const REQUIRED_COLUMNS = ['external_reference', 'customer_email', 'subject', 'description', 'priority', 'category'];

    public const OPTIONAL_COLUMNS = ['status', 'assigned_agent_email', 'created_at'];

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly TicketService $tickets,
        private readonly SlaService $sla,
    ) {}

    /** Step 1: store the file and queue the work. */
    public function start(User $admin, UploadedFile $file): TicketImport
    {
        $disk = Storage::disk((string) config('support.attachments.disk'));
        $path = $disk->putFileAs('imports', $file, Str::uuid()->toString().'.csv');

        try {
            $import = TicketImport::query()->create([
                'user_id' => $admin->getKey(),
                'original_name' => SafeFilename::clean($file->getClientOriginalName(), 'import.csv'),
                'path' => $path,
                'status' => ImportStatus::Pending,
            ]);
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        ProcessTicketImport::dispatch($import->id)->afterCommit();

        Log::info('Ticket import queued.', ['import_id' => $import->id, 'admin_id' => $admin->id]);

        return $import;
    }

    /** Step 2: runs or resumes an import; one summary notification at the end. */
    public function run(TicketImport $import): TicketImport
    {
        $import->refresh();

        if ($import->status === ImportStatus::Completed) {
            return $import;
        }

        try {
            $reader = new CsvReader(Storage::disk((string) config('support.attachments.disk'))->path($import->path));

            $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $reader->header()));
            if ($missing !== []) {
                return self::markFailed($import->id, 'Missing required columns: '.implode(', ', $missing).'.');
            }

            $import->forceFill([
                'status' => ImportStatus::Processing,
                'started_at' => $import->started_at ?? now(),
                'error_message' => null,
                // One cheap streaming pass, only the first time.
                'total_rows' => $import->total_rows > 0 ? $import->total_rows : $reader->count(),
            ])->save();

            $processed = $import->last_processed_row;

            $reader->rows()
                ->skip($processed)
                ->chunk(max(1, (int) config('support.import.chunk_size')))
                ->each(function (LazyCollection $chunk) use ($import, &$processed): void {
                    $rows = collect($chunk->all());
                    $processed += $rows->count();

                    $this->importRows($import, $rows, $processed);

                    unset($rows, $chunk); // keep memory flat between chunks
                });

            $import->refresh()->forceFill(['status' => ImportStatus::Completed, 'finished_at' => now()])->save();

            User::query()->find($import->user_id)?->notify(new ImportFinishedNotification($import));

            return $import;
        } catch (Throwable $e) {
            self::markFailed($import->id, $e->getMessage(), $e);

            throw $e;
        }
    }

    /** Status failed with a message; counters and the checkpoint stay, so a retry resumes. */
    public static function markFailed(int $importId, string $message, ?Throwable $e = null): TicketImport
    {
        $import = TicketImport::query()->findOrFail($importId);

        $import->forceFill([
            'status' => ImportStatus::Failed,
            'error_message' => mb_strimwidth($message, 0, 1000, '…'),
            'finished_at' => now(),
        ])->save();

        Log::error('Ticket import failed.', [
            'import_id' => $importId,
            'last_processed_row' => $import->last_processed_row,
            'message' => $message,
            'exception' => $e === null ? null : $e::class,
        ]);

        return $import;
    }

    /** Step 3: imports one chunk of rows. */
    public function importRows(TicketImport $import, Collection $rows, int $processedThrough): array
    {
        $now = CarbonImmutable::now();
        $errors = [];
        $candidates = [];

        // Validate each row.
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

        // Look up users, categories and existing references for the whole chunk.
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

        // Checks that need the lookups.
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

        // One transaction for the chunk, ending with the checkpoint.
        $success = DB::transaction(function () use ($import, $accepted, $newCustomers, $users, $now, $processedThrough, &$errors): int {
            $customerIds = $users->filter(fn (User $u) => $u->role === Role::Customer)->map->id->all();
            $customerIds += $this->createCustomers(array_keys($newCustomers), $now);

            // Numbers for the whole chunk; rolled back with it on failure.
            $numbers = $accepted === [] ? [] : $this->tickets->reserveNumbers(count($accepted), $now);

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
     * Creates missing customers in bulk. They get a random password (they sign in
     * through a password reset) and no welcome email unless
     * support.import.welcome_new_customers is on.
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
            'sla_policy_id' => $sla['sla_policy_id'],
            'sla_minutes' => $sla['sla_minutes'],
            'sla_warning_at' => $format($sla['sla_warning_at']),
            'due_at' => $format($sla['due_at']),
            'first_response_at' => null,
            // The file has only one timestamp; finished tickets are dated by it,
            // so imports neither inflate "resolved today" nor fake breaches.
            'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? $format($createdAt) : null,
            'closed_at' => $status === TicketStatus::Closed ? $format($createdAt) : null,
            'is_escalated' => false,
            'escalation_level' => 0,
            // Historical active tickets whose milestones already passed are marked
            // as notified, so sla:scan sends no retroactive storm of warnings.
            'warning_notified_at' => $active && $sla['sla_warning_at']->lte($now) ? $format($now) : null,
            'breached_notified_at' => $active && $sla['due_at']->lt($now) ? $format($now) : null,
            'external_reference' => $data['external_reference'],
            'source' => TicketSource::Import->value,
            'created_at' => $format($createdAt),
            'updated_at' => $format($now),
        ];
    }

    private function error(int $line, array $data, array $messages): array
    {
        return [
            'row_number' => $line,
            'raw_data' => json_encode($data),
            'errors' => json_encode($messages),
        ];
    }
}
