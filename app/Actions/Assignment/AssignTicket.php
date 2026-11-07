<?php

namespace App\Actions\Assignment;

use App\Actions\Assignment\Concerns\GuardedAssignment;
use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Exceptions\AssignmentConflict;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin assignment and reassignment, guarded by the assignee the admin SAW:
 *
 *   UPDATE tickets SET assigned_agent_id = :new ...
 *   WHERE id = ? AND assigned_agent_id <=> :expected AND status IN (active)
 *
 * (written portably as IS NULL / = ?). If another admin or agent changed the
 * assignment in the meantime, zero rows match and AssignmentConflict (409)
 * is thrown instead of silently clobbering their change.
 */
final class AssignTicket
{
    use GuardedAssignment;

    public function __construct(private readonly TicketActivityRecorder $activity) {}

    public function __invoke(User $actor, Ticket $ticket, User $agent, ?int $expectedAgentId): Ticket
    {
        if (! $agent->isAgent()) {
            throw new InvalidArgumentException('Tickets can only be assigned to agents.');
        }

        if ($expectedAgentId === $agent->getKey()) {
            return $ticket; // already this agent's as far as the admin knows: nothing to write
        }

        return DB::transaction(function () use ($actor, $ticket, $agent, $expectedAgentId): Ticket {
            $now = CarbonImmutable::now();
            $statusBefore = Ticket::query()->whereKey($ticket->getKey())->value('status');

            $affected = Ticket::query()
                ->whereKey($ticket->getKey())
                ->when(
                    $expectedAgentId === null,
                    fn ($query) => $query->whereNull('assigned_agent_id'),
                    fn ($query) => $query->where('assigned_agent_id', $expectedAgentId),
                )
                ->whereIn('status', TicketStatus::activeValues())
                ->update([
                    'assigned_agent_id' => $agent->getKey(),
                    'assigned_at' => $now,
                    'status' => self::openBecomesInProgress(),
                ]);

            if ($affected === 0) {
                throw AssignmentConflict::changedSinceViewed($ticket->ticket_number);
            }

            $ticket->refresh();
            self::touchAgent($agent, $now);
            $statusBefore = $statusBefore instanceof TicketStatus ? $statusBefore->value : $statusBefore;

            if ($expectedAgentId === null) {
                $type = ActivityType::Assigned;
                $description = "Assigned to {$agent->name} by {$actor->name}";
            } else {
                $type = ActivityType::Reassigned;
                $previousName = User::query()->whereKey($expectedAgentId)->value('name') ?? "#{$expectedAgentId}";
                $description = "Reassigned from {$previousName} to {$agent->name} by {$actor->name}";
            }

            $this->activity->record(
                $ticket,
                $type,
                $actor,
                $description,
                ['assigned_agent_id' => $expectedAgentId, 'status' => $statusBefore],
                ['assigned_agent_id' => $agent->getKey(), 'status' => $ticket->status->value],
            );

            TicketAssigned::dispatch($ticket, $agent, $actor, $expectedAgentId);
            self::announceStatusChange($ticket, $actor, $statusBefore);

            return $ticket;
        });
    }
}
