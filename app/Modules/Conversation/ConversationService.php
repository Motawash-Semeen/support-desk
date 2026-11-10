<?php

namespace App\Modules\Conversation;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Notifications\TicketNotifier;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Replies, internal notes and the clean-up of orphan attachment files. */
class ConversationService
{
    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketNotifier $notifier,
    ) {}

    /**
     * Adds a reply or internal note with attachments, in one transaction:
     *  - closed tickets take no public reply (a validation error);
     *  - the first public staff reply sets first_response_at;
     *  - a customer reply on "waiting for customer" moves the ticket to in progress
     *    (open when unassigned);
     *  - a public staff reply on an open ticket moves it to in progress.
     * Files already written are deleted if the transaction fails.
     */
    public function addReply(User $actor, Ticket $ticket, string $body, ReplyType $type, array $files = []): TicketReply
    {
        if ($type === ReplyType::InternalNote && ! $actor->isStaff()) {
            // Second guard: StoreReplyRequest never lets a customer choose the type.
            throw new InvalidArgumentException('Only staff can add internal notes.');
        }

        $stored = collect();

        try {
            return DB::transaction(function () use ($actor, $ticket, $body, $type, $files, &$stored): TicketReply {
                $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

                if ($type === ReplyType::Public && $locked->status === TicketStatus::Closed) {
                    throw ValidationException::withMessages(['body' => __('This ticket is closed. Reopen it to reply.')]);
                }

                $reply = TicketReply::query()->create([
                    'ticket_id' => $locked->getKey(),
                    'user_id' => $actor->getKey(),
                    'type' => $type,
                    'body' => $body,
                ]);

                $stored = Attachment::storeUploads($locked, $reply, $actor, $files);

                [$activityType, $description] = match (true) {
                    $type === ReplyType::InternalNote => [ActivityType::InternalNoteAdded, "Internal note added by {$actor->name}"],
                    $actor->isStaff() => [ActivityType::AgentReplied, "{$actor->name} replied"],
                    default => [ActivityType::CustomerReplied, 'Customer replied'],
                };

                TicketActivity::record($locked, $activityType, $actor, $description, [], [
                    'reply_id' => $reply->getKey(),
                    'attachments' => $stored->count(),
                ]);

                if ($type === ReplyType::Public && $actor->isStaff() && $locked->first_response_at === null) {
                    $locked->first_response_at = now();
                }

                $locked->touch(); // also saves first_response_at

                if ($next = $this->statusAfterReply($actor, $locked, $type)) {
                    $this->tickets->changeStatus($actor, $locked, $next, $actor->isStaff() ? 'Agent replied' : 'Customer replied');
                }

                $this->notifier->replyAdded($reply, $locked, $actor);

                return $reply;
            });
        } catch (Throwable $e) {
            Attachment::deleteFiles($stored);

            throw $e;
        }
    }

    /**
     * attachments:purge-orphans (daily): deletes files older than $hours that have
     * no attachments row (left behind when a process died mid-transaction).
     */
    public function purgeOrphanFiles(int $hours, bool $dryRun): array
    {
        $disk = Storage::disk((string) config('support.attachments.disk'));
        $cutoff = now()->subHours(max(1, $hours))->getTimestamp();
        $orphans = [];

        foreach (array_chunk($disk->allFiles('tickets'), 500) as $paths) {
            // One query per chunk, not per file.
            $known = Attachment::query()->whereIn('stored_name', $paths)->pluck('stored_name')->all();

            foreach (array_diff($paths, $known) as $path) {
                if ($disk->lastModified($path) > $cutoff) {
                    continue; // too new: may belong to a transaction still running
                }

                $orphans[] = $path;

                if (! $dryRun) {
                    $disk->delete($path);
                }
            }
        }

        if (! $dryRun && $orphans !== []) {
            Log::info('Orphan attachment files purged.', ['count' => count($orphans)]);
        }

        return $orphans;
    }

    private function statusAfterReply(User $actor, Ticket $ticket, ReplyType $type): ?TicketStatus
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
