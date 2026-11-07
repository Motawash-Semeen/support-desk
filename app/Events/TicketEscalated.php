<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Escalation above level 1. (Level 1 travels with SlaBreached, which already
 * notifies the assignee and every admin.)
 */
class TicketEscalated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public int $level,
    ) {}
}
