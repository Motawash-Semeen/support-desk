<?php

namespace App\Modules\Assignment\Actions;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Services\TicketActivityRecorder;
use App\Modules\Assignment\Actions\Concerns\GuardedAssignment;
use App\Modules\Assignment\Events\TicketAssigned;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The concurrency primitive for taking an UNASSIGNED ticket: one conditional
 * UPDATE (compare-and-set).
 *
 *   UPDATE tickets SET assigned_agent_id = ?, assigned_at = ?, status = CASE ... END
 *   WHERE id = ? AND assigned_agent_id IS NULL AND status IN (active)
 *
 * InnoDB row-locks the row for the statement; a concurrent second UPDATE
 * waits, re-evaluates the WHERE against the committed row and matches zero
 * rows. The affected-row count is the database's own answer to "did my write
 * apply": 1 = this caller won, 0 = someone else did. Nothing is overwritten.
 *
 * $actor: the person who acted (agent accepting, admin), or null for the
 * system (auto-assignment).
 */
final class ClaimTicket
{
    use GuardedAssignment;

    public function __construct(private readonly TicketActivityRecorder $activity) {}

    public function __invoke(Ticket $ticket, User $agent, ?User $actor = null): bool
    {
        if (! $agent->isAgent()) {
            throw new InvalidArgumentException('Only agents can be assigned tickets.');
        }

        return DB::transaction(function () use ($ticket, $agent, $actor): bool {
            $now = CarbonImmutable::now();
            $statusBefore = Ticket::query()->whereKey($ticket->getKey())->value('status');

            $affected = Ticket::query()
                ->whereKey($ticket->getKey())
                ->whereNull('assigned_agent_id')
                ->whereIn('status', TicketStatus::activeValues())
                ->update([
                    'assigned_agent_id' => $agent->getKey(),
                    'assigned_at' => $now,
                    'status' => self::openBecomesInProgress(),
                ]);

            if ($affected === 0) {
                return false; // someone else won (or the ticket is no longer active)
            }

            $ticket->refresh();
            self::touchAgent($agent, $now);

            $this->activity->record(
                $ticket,
                ActivityType::Assigned,
                $actor,
                match (true) {
                    $actor === null => "Auto-assigned to {$agent->name}",
                    $actor->is($agent) => "Accepted by {$agent->name}",
                    default => "Assigned to {$agent->name}",
                },
                ['assigned_agent_id' => null, 'status' => $statusBefore instanceof TicketStatus ? $statusBefore->value : $statusBefore],
                ['assigned_agent_id' => $agent->getKey(), 'status' => $ticket->status->value],
            );

            TicketAssigned::dispatch($ticket, $agent, $actor, null);
            self::announceStatusChange($ticket, $actor, $statusBefore instanceof TicketStatus ? $statusBefore->value : $statusBefore);

            return true;
        });
    }
}
