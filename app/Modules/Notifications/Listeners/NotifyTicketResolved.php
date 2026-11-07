<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Listeners\Concerns\ResolvesRecipients;
use App\Modules\Notifications\Notifications\TicketResolvedNotification;
use App\Modules\Tickets\Events\TicketResolved;
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
