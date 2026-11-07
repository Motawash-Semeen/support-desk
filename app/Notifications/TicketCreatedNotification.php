<?php

namespace App\Notifications;

use App\Models\Ticket;

/** Receipt for the customer, or "new ticket" alert for staff. */
class TicketCreatedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public bool $forCustomer)
    {
        parent::__construct($ticket);
    }

    protected function subject(): string
    {
        return $this->forCustomer ? 'We received your ticket' : 'New ticket: '.$this->ticket->subject;
    }

    protected function message(): string
    {
        return $this->forCustomer
            ? "Your ticket {$this->ticket->ticket_number} has been received. We will get back to you soon."
            : "New {$this->ticket->priority->label()} priority ticket {$this->ticket->ticket_number}: {$this->ticket->subject}";
    }
}
