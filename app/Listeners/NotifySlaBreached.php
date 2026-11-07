<?php

namespace App\Listeners;

use App\Events\SlaBreached;
use App\Listeners\Concerns\ResolvesRecipients;
use App\Notifications\SlaBreachedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** The assignee and every admin. */
class NotifySlaBreached implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use ResolvesRecipients;

    public function handle(SlaBreached $event): void
    {
        Notification::send(
            $this->recipients([$this->assigneeOf($event->ticket), $this->admins()], null),
            new SlaBreachedNotification($event->ticket),
        );
    }
}
