<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Tickets\Models\Ticket;

class TicketAssignedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public string $agentName, public bool $forAgent)
    {
        parent::__construct($ticket);
    }

    protected function subject(): string
    {
        return $this->forAgent ? 'Ticket assigned to you' : 'Your ticket has an agent';
    }

    protected function message(): string
    {
        return $this->forAgent
            ? "{$this->ticket->ticket_number} ({$this->ticket->subject}) is now assigned to you."
            : "{$this->agentName} is now handling your ticket {$this->ticket->ticket_number}.";
    }
}
