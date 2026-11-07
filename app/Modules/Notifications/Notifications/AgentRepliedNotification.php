<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** To the customer. Can never carry an internal note (constructor guard). */
class AgentRepliedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public TicketReply $reply, public string $authorName)
    {
        if ($reply->isInternal()) {
            throw new InvalidArgumentException('Internal notes are never sent to customers.');
        }

        parent::__construct($ticket);
    }

    protected function subject(): string
    {
        return 'New reply on your ticket';
    }

    protected function message(): string
    {
        return "{$this->authorName} replied to your ticket {$this->ticket->ticket_number}.";
    }

    protected function details(): array
    {
        return [Str::limit($this->reply->body, 500)];
    }
}
