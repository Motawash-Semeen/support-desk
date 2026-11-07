<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Events\ReplyAdded;
use App\Modules\Notifications\Listeners\Concerns\ResolvesRecipients;
use App\Modules\Notifications\Notifications\AgentRepliedNotification;
use App\Modules\Notifications\Notifications\CustomerRepliedNotification;
use App\Modules\Notifications\Notifications\InternalNoteAddedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Internal note  -> the assignee (if someone else wrote it); NEVER the customer.
 * Staff reply    -> the customer.
 * Customer reply -> the assignee, or every admin while unassigned.
 */
class NotifyReplyAdded implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use ResolvesRecipients;

    public function handle(ReplyAdded $event): void
    {
        $ticket = $event->ticket;
        $author = $event->author;

        if ($event->type === ReplyType::InternalNote) {
            Notification::send(
                $this->recipients([$this->assigneeOf($ticket)], $author),
                new InternalNoteAddedNotification($ticket, $event->reply, $author->name),
            );

            return;
        }

        if ($author->isStaff()) {
            Notification::send(
                $this->recipients([$this->customerOf($ticket)], $author),
                new AgentRepliedNotification($ticket, $event->reply, $author->name),
            );

            return;
        }

        Notification::send(
            $this->recipients([$this->assigneeOf($ticket) ?? $this->admins()], $author),
            new CustomerRepliedNotification($ticket, $event->reply),
        );
    }
}
