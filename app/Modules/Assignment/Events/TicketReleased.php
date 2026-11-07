<?php

namespace App\Modules\Assignment\Events;

use App\Models\User;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A ticket went back to the unassigned queue. */
class TicketReleased implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public User $actor,
        public int $previousAgentId,
    ) {}
}
