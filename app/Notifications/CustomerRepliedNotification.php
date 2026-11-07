<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
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
