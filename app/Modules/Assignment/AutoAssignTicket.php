<?php

namespace App\Modules\Assignment;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued AssignmentService::autoAssign(). Unique per ticket, so a ticket is never queued twice. */
class AutoAssignTicket implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    /** The unique lock expires even if a worker dies. */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $ticketId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(AssignmentService $assignment): void
    {
        $assignment->autoAssign($this->ticketId);
    }
}
