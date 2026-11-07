<?php

namespace App\Services\Assignment;

use App\Models\Ticket;
use App\Models\User;

/**
 * Alternative strategy: the candidate who was assigned least recently
 * (never-assigned agents first). Ignores workload, so one agent can end up
 * holding all the critical tickets; provided to show the strategy seam.
 */
final class RoundRobinStrategy implements AssignmentStrategy
{
    public function __construct(private readonly CandidateAgents $pool) {}

    public function pick(Ticket $ticket): ?User
    {
        $ids = $this->pool->lock($this->pool->idsFor($ticket));

        if ($ids === []) {
            return null;
        }

        return User::query()
            ->whereKey($ids)
            ->orderByRaw('last_assigned_at IS NOT NULL')
            ->orderBy('last_assigned_at')
            ->orderBy('id')
            ->first();
    }
}
