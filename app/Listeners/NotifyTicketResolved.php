<?php

namespace App\Listeners;

use App\Events\TicketResolved;
use App\Listeners\Concerns\ResolvesRecipients;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** The customer, unless they resolved it themselves. */
class NotifyTicketResolved implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use ResolvesRecipients;

    public function handle(TicketResolved $event): void
    {
        Notification::send(
            $this->recipients([$this->customerOf($event->ticket)], $event->actor),
            new TicketResolvedNotification($event->ticket),
        );
    }
}
