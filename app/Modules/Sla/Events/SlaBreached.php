<?php

namespace App\Modules\Sla\Events;

use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Dispatched once per deadline, when breached_notified_at is first set. */
class SlaBreached implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Ticket $ticket) {}
}
