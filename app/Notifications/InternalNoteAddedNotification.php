<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Str;

/** Staff only: sent to the assignee when someone else leaves a note. */
class InternalNoteAddedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public TicketReply $note, public string $authorName)
    {
        parent::__construct($ticket);
    }

    protected function subject(): string
    {
        return 'Internal note added';
    }

    protected function message(): string
    {
        return "{$this->authorName} added an internal note on {$this->ticket->ticket_number}.";
    }

    protected function details(): array
    {
        return [Str::limit($this->note->body, 500)];
    }
}
