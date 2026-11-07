<?php

namespace App\Actions\Assignment;

use App\Models\Ticket;
use App\Models\User;

/**
 * An agent takes an unassigned ticket from the queue. Thin wrapper around
 * ClaimTicket; a repeated accept by the agent who already won (double click)
 * counts as success.
 *
 * @return bool false when another agent won the race
 */
final class AcceptTicket
{
    public function __construct(private readonly ClaimTicket $claim) {}

    public function __invoke(User $agent, Ticket $ticket): bool
    {
        if (($this->claim)($ticket, $agent, $agent)) {
            return true;
        }

        return Ticket::query()->whereKey($ticket->getKey())->value('assigned_agent_id') === $agent->getKey();
    }
}
