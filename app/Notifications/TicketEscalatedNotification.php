<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Support\BusinessTime;

class TicketEscalatedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public int $level)
    {
        parent::__construct($ticket);
    }

    protected function subject(): string
    {
        return "Escalated to level {$this->level}";
    }

    protected function message(): string
    {
        return "{$this->ticket->ticket_number} is still open long after its deadline (".BusinessTime::format($this->ticket->due_at).') and was escalated to level '.$this->level.'.';
    }
}
