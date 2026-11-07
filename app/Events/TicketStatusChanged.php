<?php

namespace App\Events;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public ?User $actor,
        public TicketStatus $from,
        public TicketStatus $to,
        public ?string $reason = null,
    ) {}
}
