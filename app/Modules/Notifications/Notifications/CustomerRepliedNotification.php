<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Str;

class CustomerRepliedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public TicketReply $reply)
    {
        parent::__construct($ticket);
    }

    protected function subject(): string
    {
        return 'Customer replied';
    }

    protected function message(): string
    {
        return "The customer replied on {$this->ticket->ticket_number}.";
    }

    protected function details(): array
    {
        return [Str::limit($this->reply->body, 500)];
    }
}
