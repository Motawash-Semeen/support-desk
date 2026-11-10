<?php

namespace App\Modules\Tickets;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Assignment\AutoAssignTicket;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Notifications\TicketNotifier;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketSource;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Every write to a ticket: create, edit, status change, auto-close and numbering.
 * Each is one transaction with its timeline entry; notifications go after the commit.
 */
class TicketService
{
    private const EDITABLE = ['subject', 'description', 'priority', 'category_id'];

    public function __construct(
        private readonly SlaService $sla,
        private readonly TicketNotifier $notifier,
    ) {}

    /** Opens a ticket in one transaction; on failure the number is rolled back and the files removed. */
    public function create(User $actor, array $data, array $files = []): Ticket
    {
        $stored = collect();

        try {
            return DB::transaction(function () use ($actor, $data, $files, &$stored): Ticket {
                $now = CarbonImmutable::now();
                $priority = $data['priority'] instanceof TicketPriority ? $data['priority'] : TicketPriority::from($data['priority']);

                $ticket = Ticket::query()->create([
                    'ticket_number' => $this->reserveNumbers(1, $now)[0],
                    'subject' => $data['subject'],
                    'description' => $data['description'],
                    'priority' => $priority,
                    'status' => TicketStatus::Open,
                    'category_id' => (int) $data['category_id'],
                    'created_by' => $actor->getKey(),
                    'source' => $data['source'] ?? TicketSource::Web,
                    'external_reference' => $data['external_reference'] ?? null,
                ] + $this->sla->calculate($priority, $now));

                TicketActivity::record($ticket, ActivityType::TicketCreated, $actor, "Ticket {$ticket->ticket_number} created", [], [
                    'status' => TicketStatus::Open->value,
                    'priority' => $priority->value,
                    'category_id' => $ticket->category_id,
                ]);

                $stored = Attachment::storeUploads($ticket, null, $actor, $files);

                // Both wait for the commit.
                $this->notifier->ticketCreated($ticket, $actor);

                if (config('support.assignment.auto_on_create')) {
                    AutoAssignTicket::dispatch($ticket->getKey())->afterCommit();
                }

                return $ticket;
            });
        } catch (Throwable $e) {
            Attachment::deleteFiles($stored); // the rows rolled back; remove the files too

            throw $e;
        }
    }

    /**
     * Edits the fields UpdateTicketRequest allowed: one timeline entry per field, and
     * a new deadline when the priority changes.
     */
    public function update(User $actor, Ticket $ticket, array $data): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket, $data): Ticket {
            // Lock, so the recorded "old" values are the ones actually replaced.
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            $input = Arr::only($data, self::EDITABLE);
            if (array_key_exists('category_id', $input)) {
                $input['category_id'] = (int) $input['category_id'];
            }

            $locked->fill($input);

            if (! $locked->isDirty()) {
                return $locked;
            }

            $changes = [];
            foreach (array_keys($locked->getDirty()) as $field) {
                $changes[$field] = ['old' => $locked->getRawOriginal($field), 'new' => $locked->getAttributes()[$field]];
            }

            $locked->save();

            foreach ($changes as $field => $change) {
                $this->recordFieldChange($actor, $locked, $field, $change['old'], $change['new']);
            }

            // From the original created_at: time already spent still counts.
            if (isset($changes['priority']) && $this->sla->recalculate($locked, $locked->created_at, $actor, 'priority changed')) {
                $locked->save();
            }

            return $locked;
        });
    }

    /**
     * The only place a ticket's status changes. An illegal move is a validation error
     * on `status`. A null actor is the system, which gets no admin-only moves.
     */
    public function changeStatus(?User $actor, Ticket $ticket, TicketStatus $to, ?string $reason = null): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket, $to, $reason): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to, (bool) $actor?->isAdmin())) {
                Log::info('Rejected ticket status transition.', ['ticket_id' => $locked->id, 'from' => $from->value, 'to' => $to->value, 'user_id' => $actor?->id]);

                throw ValidationException::withMessages(['status' => "A ticket cannot move from {$from->label()} to {$to->label()}."]);
            }

            $now = CarbonImmutable::now();
            $reopening = ! $from->isActive() && $to->isActive();

            $locked->status = $to;

            if ($to === TicketStatus::Resolved) {
                $locked->resolved_at = $now;
            }

            if ($to === TicketStatus::Closed) {
                $locked->closed_at = $now;
            }

            if ($reopening) {
                $locked->fill(['resolved_at' => null, 'closed_at' => null, 'is_escalated' => false, 'escalation_level' => 0]);
            }

            $locked->save();

            TicketActivity::record(
                $locked,
                $reopening ? ActivityType::Reopened : ActivityType::StatusChanged,
                $actor,
                $reopening
                    ? "Ticket reopened ({$from->label()} to {$to->label()})"
                    : "Status changed from {$from->label()} to {$to->label()}",
                ['status' => $from->value],
                array_filter(['status' => $to->value, 'reason' => $reason]),
            );

            // A reopened ticket gets a fresh deadline counted from the reopen time.
            if ($reopening && $this->sla->recalculate($locked, $now, $actor, 'ticket reopened')) {
                $locked->save();
            }

            if ($to === TicketStatus::Resolved) {
                $this->notifier->resolved($locked, $actor);
            }

            return $locked;
        });
    }

    /** tickets:auto-close-resolved (daily): closes tickets resolved for $days days; reopened ones are skipped. */
    public function autoCloseResolved(int $days): int
    {
        $closed = 0;

        Ticket::query()
            ->where('status', TicketStatus::Resolved)
            ->where('resolved_at', '<=', now()->subDays($days))
            ->select(['id'])
            ->chunkById(200, function ($tickets) use ($days, &$closed): void {
                foreach ($tickets as $ticket) {
                    try {
                        $this->changeStatus(null, $ticket, TicketStatus::Closed, "Closed automatically after {$days} days resolved");
                        $closed++;
                    } catch (ValidationException) {
                        // Reopened or closed meanwhile: nothing to do.
                    }
                }
            });

        return $closed;
    }

    /**
     * Reserves $count numbers from the year's counter row (locked). Call it inside the
     * inserting transaction, so a rollback leaves no gap.
     */
    public function reserveNumbers(int $count, ?CarbonInterface $at = null): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('At least one ticket number must be reserved.');
        }

        $year = ($at ?? now())->year;

        $last = DB::transaction(function () use ($year, $count): int {
            DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);

            $current = (int) DB::table('ticket_sequences')->where('year', $year)->lockForUpdate()->value('last_number');

            DB::table('ticket_sequences')->where('year', $year)->update(['last_number' => $current + $count]);

            return $current + $count;
        });

        return array_map(fn (int $number): string => $this->formatNumber($year, $number), range($last - $count + 1, $last));
    }

    /** Padding is a minimum width: number 1,000,000 becomes TKT-2026-1000000. */
    public function formatNumber(int $year, int $number): string
    {
        return sprintf('%s-%d-%0'.(int) config('support.ticket_number_padding').'d', config('support.ticket_prefix'), $year, $number);
    }

    private function recordFieldChange(User $actor, Ticket $ticket, string $field, mixed $old, mixed $new): void
    {
        [$type, $description] = match ($field) {
            'priority' => [
                ActivityType::PriorityChanged,
                sprintf('Priority changed from %s to %s', TicketPriority::from($old)->label(), TicketPriority::from($new)->label()),
            ],
            'category_id' => [ActivityType::CategoryChanged, $this->categoryChange((int) $old, (int) $new)],
            'subject' => [ActivityType::TicketUpdated, 'Subject changed'],
            default => [ActivityType::TicketUpdated, 'Description edited'],
        };

        TicketActivity::record($ticket, $type, $actor, $description, [$field => $old], [$field => $new]);
    }

    private function categoryChange(int $old, int $new): string
    {
        $names = Category::query()->whereKey([$old, $new])->pluck('name', 'id');

        return sprintf('Category changed from %s to %s', $names[$old] ?? "#{$old}", $names[$new] ?? "#{$new}");
    }
}
