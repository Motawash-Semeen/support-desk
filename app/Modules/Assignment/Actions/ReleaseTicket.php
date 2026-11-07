<?php

namespace App\Modules\Assignment\Actions;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Services\TicketActivityRecorder;
use App\Modules\Assignment\Actions\Concerns\GuardedAssignment;
use App\Modules\Assignment\Events\TicketReleased;
use App\Modules\Assignment\Exceptions\AssignmentConflict;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Puts an assigned ticket back in the unassigned queue (status Open).
 * Guarded: WHERE assigned_agent_id = the releasing agent (or, for an admin,
 * the assignee the admin saw), so a release can never undo a newer
 * assignment made by someone else.
 */
final class ReleaseTicket
{
    use GuardedAssignment;

    public function __construct(private readonly TicketActivityRecorder $activity) {}

    public function __invoke(User $actor, Ticket $ticket): Ticket
    {
        $expected = $actor->isAdmin() ? $ticket->assigned_agent_id : $actor->getKey();

        if ($expected === null) {
            throw AssignmentConflict::noLongerAssigned($ticket->ticket_number);
        }

        return DB::transaction(function () use ($actor, $ticket, $expected): Ticket {
            $statusBefore = Ticket::query()->whereKey($ticket->getKey())->value('status');

            $affected = Ticket::query()
                ->whereKey($ticket->getKey())
                ->where('assigned_agent_id', $expected)
                ->whereIn('status', TicketStatus::activeValues())
                ->update([
                    'assigned_agent_id' => null,
                    'assigned_at' => null,
                    'status' => TicketStatus::Open->value,
                ]);

            if ($affected === 0) {
                throw AssignmentConflict::noLongerAssigned($ticket->ticket_number);
            }

            $ticket->refresh();
            $statusBefore = $statusBefore instanceof TicketStatus ? $statusBefore->value : $statusBefore;

            $this->activity->record(
                $ticket,
                ActivityType::Released,
                $actor,
                "Released to the unassigned queue by {$actor->name}",
                ['assigned_agent_id' => $expected, 'status' => $statusBefore],
                ['assigned_agent_id' => null, 'status' => TicketStatus::Open->value],
            );

            TicketReleased::dispatch($ticket, $actor, $expected);
            self::announceStatusChange($ticket, $actor, $statusBefore);

            return $ticket;
        });
    }
}
