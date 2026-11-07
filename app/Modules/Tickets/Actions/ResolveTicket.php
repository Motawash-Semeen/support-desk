<?php

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;

/** Marks a ticket resolved (sets resolved_at, dispatches TicketResolved). */
final class ResolveTicket
{
    public function __construct(private readonly ChangeTicketStatus $changeStatus) {}

    public function __invoke(User $actor, Ticket $ticket, ?string $reason = null): Ticket
    {
        return ($this->changeStatus)($actor, $ticket, TicketStatus::Resolved, $reason);
    }
}
