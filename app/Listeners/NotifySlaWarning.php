<?php

namespace App\Listeners;

use App\Events\SlaWarningReached;
use App\Listeners\Concerns\ResolvesRecipients;
use App\Notifications\SlaApproachingNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** The assignee, or the admins who own the unassigned queue. */
class NotifySlaWarning implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use ResolvesRecipients;

    public function handle(SlaWarningReached $event): void
    {
        Notification::send(
            $this->recipients([$this->assigneeOf($event->ticket) ?? $this->admins()], null),
            new SlaApproachingNotification($event->ticket),
        );
    }
}
