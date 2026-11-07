<?php

namespace App\Modules\Assignment\Jobs;

use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Services\TicketActivityRecorder;
use App\Modules\Assignment\Actions\ClaimTicket;
use App\Modules\Assignment\Services\AssignmentStrategy;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-assigns one ticket. Unique per ticket id, so the create hook and the
 * five-minute scheduler retry cannot queue the same ticket twice.
 *
 * Inside one transaction: the strategy locks the candidate agents (id order)
 * and reads their workload, then ClaimTicket does the compare-and-set. If an
 * agent accepted the ticket in the meantime, ClaimTicket returns false and the
 * job simply ends: the ticket is assigned, which is the goal.
 */
class AutoAssignTicket implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    /** Safety net: the unique lock expires even if a worker dies mid-job. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $ticketId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(AssignmentStrategy $strategy, ClaimTicket $claim, TicketActivityRecorder $activity): void
    {
        $ticket = Ticket::query()->find($this->ticketId);

        if ($ticket === null || $ticket->assigned_agent_id !== null || ! $ticket->isActive()) {
            return;
        }

        DB::transaction(function () use ($ticket, $strategy, $claim, $activity): void {
            $agent = $strategy->pick($ticket);

            if ($agent === null) {
                $this->recordSkip($ticket, $activity);

                return;
            }

            $claim($ticket, $agent, null);
        });
    }

    /**
     * One `auto_assign_skipped` entry per unassigned stretch, not one every
     * five minutes: skipped when it is already the latest activity.
     */
    private function recordSkip(Ticket $ticket, TicketActivityRecorder $activity): void
    {
        $latest = $ticket->activities()->latest()->latest('id')->value('type');

        if ($latest !== ActivityType::AutoAssignSkipped) {
            $activity->record($ticket, ActivityType::AutoAssignSkipped, null, 'No available agent; the ticket stays in the unassigned queue and is retried every 5 minutes');
        }

        Log::info('Auto-assignment found no available agent.', ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number]);
    }
}
