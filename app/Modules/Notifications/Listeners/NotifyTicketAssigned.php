<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Assignment\Events\TicketAssigned;
use App\Modules\Notifications\Listeners\Concerns\ResolvesRecipients;
use App\Modules\Notifications\Notifications\TicketAssignedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** The assigned agent (unless they accepted it themselves) and the customer. */
class NotifyTicketAssigned implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use ResolvesRecipients;

    public function handle(TicketAssigned $event): void
    {
        $ticket = $event->ticket;
        $agentName = $event->agent->name;

        Notification::send(
            $this->recipients([$event->agent], $event->actor),
            new TicketAssignedNotification($ticket, $agentName, forAgent: true),
        );

        Notification::send(
            $this->recipients([$this->customerOf($ticket)], $event->actor),
            new TicketAssignedNotification($ticket, $agentName, forAgent: false),
        );
    }
}
