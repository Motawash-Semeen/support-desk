<?php

namespace App\Listeners;

use App\Events\TicketEscalated;
use App\Listeners\Concerns\ResolvesRecipients;
use App\Notifications\TicketEscalatedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** Higher escalation levels go to every admin. */
class NotifyTicketEscalated implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use ResolvesRecipients;

    public function handle(TicketEscalated $event): void
    {
        Notification::send(
            $this->recipients([$this->admins()], null),
            new TicketEscalatedNotification($event->ticket, $event->level),
        );
    }
}
