<?php

namespace App\Actions\Replies;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\ActivityType;
use App\Enums\ReplyType;
use App\Enums\TicketStatus;
use App\Events\ReplyAdded;
use App\Exceptions\ReplyNotAllowed;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Adds a public reply or an internal note, with attachments, in ONE
 * transaction, and applies the conversation rules:
 *
 *  - first public staff reply sets first_response_at;
 *  - a customer reply on "waiting for customer" moves the ticket back to
 *    in progress (or open when nobody is assigned);
 *  - a staff public reply on an open ticket moves it to in progress;
 *  - closed tickets take no public replies (reopen first);
 *  - one activity per reply (customer_replied / agent_replied /
 *    internal_note_added), never containing the reply text;
 *  - ReplyAdded after commit.
 *
 * If the transaction rolls back, files already written are deleted.
 */
final class AddReply
{
    public function __construct(
        private readonly StoreAttachments $storeAttachments,
        private readonly ChangeTicketStatus $changeStatus,
        private readonly TicketActivityRecorder $activity,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function __invoke(User $actor, Ticket $ticket, string $body, ReplyType $type, array $files = []): TicketReply
    {
        if ($type === ReplyType::InternalNote && ! $actor->isStaff()) {
            // Defence in depth: the controller never lets a customer choose the type.
            throw new InvalidArgumentException('Only staff can add internal notes.');
        }

        /** @var Collection<int, Attachment> $stored */
        $stored = collect();

        try {
            return DB::transaction(function () use ($actor, $ticket, $body, $type, $files, &$stored): TicketReply {
                $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

                if ($type === ReplyType::Public && $locked->status === TicketStatus::Closed) {
                    throw ReplyNotAllowed::ticketClosed();
                }

                $reply = $locked->replies()->create([
                    'user_id' => $actor->getKey(),
                    'type' => $type,
                    'body' => $body,
                ]);

                $stored = ($this->storeAttachments)($locked, $reply, $actor, $files);

                [$activityType, $description] = match (true) {
                    $type === ReplyType::InternalNote => [ActivityType::InternalNoteAdded, "Internal note added by {$actor->name}"],
                    $actor->isStaff() => [ActivityType::AgentReplied, "{$actor->name} replied"],
                    default => [ActivityType::CustomerReplied, 'Customer replied'],
                };

                $this->activity->record($locked, $activityType, $actor, $description, [], [
                    'reply_id' => $reply->getKey(),
                    'attachments' => $stored->count(),
                ]);

                if ($type === ReplyType::Public && $actor->isStaff() && $locked->first_response_at === null) {
                    $locked->first_response_at = now();
                }

                $locked->touch(); // saves first_response_at too

                if ($next = $this->nextStatus($actor, $locked, $type)) {
                    ($this->changeStatus)($actor, $locked, $next, $actor->isStaff() ? 'Agent replied' : 'Customer replied');
                }

                ReplyAdded::dispatch($reply, $locked, $actor, $type);

                return $reply;
            });
        } catch (Throwable $e) {
            StoreAttachments::discard($stored);

            throw $e;
        }
    }

    private function nextStatus(User $actor, Ticket $ticket, ReplyType $type): ?TicketStatus
    {
        if ($type !== ReplyType::Public) {
            return null; // notes never change what the customer sees
        }

        if (! $actor->isStaff() && $ticket->status === TicketStatus::WaitingForCustomer) {
            return $ticket->assigned_agent_id === null ? TicketStatus::Open : TicketStatus::InProgress;
        }

        if ($actor->isStaff() && $ticket->status === TicketStatus::Open) {
            return TicketStatus::InProgress;
        }

        return null;
    }
}
