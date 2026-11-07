<?php

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;

/** Closes a ticket (allowed from Waiting for customer or Resolved). */
final class CloseTicket
{
    public function __construct(private readonly ChangeTicketStatus $changeStatus) {}

    public function __invoke(?User $actor, Ticket $ticket, ?string $reason = null): Ticket
    {
        return ($this->changeStatus)($actor, $ticket, TicketStatus::Closed, $reason);
    }
}
