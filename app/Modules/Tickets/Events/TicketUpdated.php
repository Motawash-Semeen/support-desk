<?php

namespace App\Modules\Tickets\Events;

use App\Models\User;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    public function __construct(
        public Ticket $ticket,
        public User $actor,
        public array $changes,
    ) {}
}
