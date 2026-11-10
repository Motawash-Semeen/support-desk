<?php

namespace App\Modules\Sla;

use App\Modules\Sla\Enums\EscalationKind;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One escalation step for one ticket, queued by sla:scan. Unique per ticket and
 * kind; the guarded UPDATE in SlaService::escalate() makes repeats harmless.
 */
class EscalateTicketJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /** The unique lock expires even if a worker dies. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $ticketId,
        public readonly EscalationKind $kind,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->ticketId}:{$this->kind->value}";
    }

    public function handle(SlaService $sla): void
    {
        $sla->escalate($this->ticketId, $this->kind);
    }
}
