<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Listeners\Concerns\ResolvesRecipients;
use App\Modules\Notifications\Notifications\SlaApproachingNotification;
use App\Modules\Sla\Events\SlaWarningReached;
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
