<?php

namespace App\Notifications;

class TicketResolvedNotification extends TicketNotification
{
    protected function subject(): string
    {
        return 'Your ticket was resolved';
    }

    protected function message(): string
    {
        return "Your ticket {$this->ticket->ticket_number} was marked resolved. Reply or reopen it if the problem is not fixed.";
    }
}
