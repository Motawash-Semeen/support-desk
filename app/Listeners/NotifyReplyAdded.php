<?php

namespace App\Listeners;

use App\Enums\ReplyType;
use App\Events\ReplyAdded;
use App\Listeners\Concerns\ResolvesRecipients;
use App\Notifications\AgentRepliedNotification;
use App\Notifications\CustomerRepliedNotification;
use App\Notifications\InternalNoteAddedNotification;
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
