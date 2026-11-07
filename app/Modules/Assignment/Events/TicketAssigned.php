<?php

namespace App\Modules\Assignment\Events;

use App\Models\User;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /** $actor is null when the system assigned the ticket. */
    public function __construct(
        public Ticket $ticket,
        public User $agent,
        public ?User $actor,
        public ?int $previousAgentId = null,
    ) {}
}
