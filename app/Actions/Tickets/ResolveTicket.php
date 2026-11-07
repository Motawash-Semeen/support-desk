<?php

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

/** Marks a ticket resolved (sets resolved_at, dispatches TicketResolved). */
final class ResolveTicket
{
    public function __construct(private readonly ChangeTicketStatus $changeStatus) {}

    public function __invoke(User $actor, Ticket $ticket, ?string $reason = null): Ticket
    {
        return ($this->changeStatus)($actor, $ticket, TicketStatus::Resolved, $reason);
    }
}
