<?php

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

/** Closes a ticket (allowed from Waiting for customer or Resolved). */
final class CloseTicket
{
    public function __construct(private readonly ChangeTicketStatus $changeStatus) {}

    public function __invoke(?User $actor, Ticket $ticket, ?string $reason = null): Ticket
    {
        return ($this->changeStatus)($actor, $ticket, TicketStatus::Closed, $reason);
    }
}
