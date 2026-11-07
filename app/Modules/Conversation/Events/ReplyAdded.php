<?php

namespace App\Modules\Conversation\Events;

use App\Models\User;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after commit. Carries the reply type so listeners (e.g. customer
 * notifications) can ignore internal notes without loading the reply.
 */
class ReplyAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TicketReply $reply,
        public Ticket $ticket,
        public User $author,
        public ReplyType $type,
    ) {}
}
