<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Listeners\Concerns\ResolvesRecipients;
use App\Modules\Notifications\Notifications\TicketEscalatedNotification;
use App\Modules\Sla\Events\TicketEscalated;
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
