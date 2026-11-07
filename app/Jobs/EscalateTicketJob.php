<?php

namespace App\Jobs;

use App\Actions\Sla\EscalateTicket;
use App\Enums\EscalationKind;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One escalation step for one ticket, queued by sla:scan. Unique per
 * ticket + kind, so back-to-back scans do not queue the same work twice; the
 * guarded UPDATE inside EscalateTicket is the real guarantee (a duplicate or
 * retried job changes nothing). One failing ticket never stops the others.
 */
class EscalateTicketJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /** Safety net: the unique lock expires even if a worker dies mid-job. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $ticketId,
        public readonly EscalationKind $kind,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->ticketId}:{$this->kind->value}";
    }

    public function handle(EscalateTicket $escalate): void
    {
        $escalate($this->ticketId, $this->kind);
    }
}
