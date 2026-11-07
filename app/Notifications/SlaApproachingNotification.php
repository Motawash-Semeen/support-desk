<?php

namespace App\Notifications;

use App\Support\BusinessTime;

class SlaApproachingNotification extends TicketNotification
{
    protected function subject(): string
    {
        return 'SLA deadline approaching';
    }

    protected function message(): string
    {
        return "{$this->ticket->ticket_number} is due ".BusinessTime::format($this->ticket->due_at).' ('.BusinessTime::zone().').';
    }
}
