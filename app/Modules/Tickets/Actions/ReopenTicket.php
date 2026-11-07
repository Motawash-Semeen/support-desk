<?php

namespace App\Modules\Tickets\Actions;

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;

/**
 * Back to In progress. ChangeTicketStatus clears resolved_at / closed_at and
 * restarts the SLA; reopening a Closed ticket is admin-only (enum rule).
 */
final class ReopenTicket
{
    public function __construct(private readonly ChangeTicketStatus $changeStatus) {}

    public function __invoke(User $actor, Ticket $ticket, ?string $reason = null): Ticket
    {
        return ($this->changeStatus)($actor, $ticket, TicketStatus::reopenTarget(), $reason);
    }
}
