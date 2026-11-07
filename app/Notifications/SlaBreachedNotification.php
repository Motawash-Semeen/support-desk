<?php

namespace App\Notifications;

use App\Support\BusinessTime;

class SlaBreachedNotification extends TicketNotification
{
    protected function subject(): string
    {
        return 'SLA breached';
    }

    protected function message(): string
    {
        return "{$this->ticket->ticket_number} missed its deadline of ".BusinessTime::format($this->ticket->due_at).' ('.BusinessTime::zone().').';
    }
}
