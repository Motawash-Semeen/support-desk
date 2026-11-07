<?php

namespace App\Modules\Assignment\Services;

use App\Models\User;
use App\Modules\Tickets\Models\Ticket;

/**
 * Chooses the agent a ticket should be auto-assigned to, or null when no
 * agent is available. Selected by config('support.assignment.strategy') and
 * resolved from the container.
 *
 * Call pick() inside a transaction: implementations lock the candidate
 * agents' rows so concurrent jobs see each other's assignments.
 */
interface AssignmentStrategy
{
    public function pick(Ticket $ticket): ?User;
}
