<?php

namespace App\Services\Sla;

use Carbon\CarbonImmutable;

/**
 * Result of an SLA calculation, written onto the ticket as a snapshot.
 * warningAt and dueAt are UTC instants.
 */
final readonly class SlaResult
{
    public function __construct(
        public ?int $policyId,
        public int $minutes,
        public CarbonImmutable $warningAt,
        public CarbonImmutable $dueAt,
    ) {}

    /**
     * Ticket columns for this result. Notification markers are cleared when
     * requested, so warnings and breaches fire again for a future deadline.
     *
     * @return array<string, mixed>
     */
    public function toTicketAttributes(bool $resetNotifications = true): array
    {
        $attributes = [
            'sla_policy_id' => $this->policyId,
            'sla_minutes' => $this->minutes,
            'sla_warning_at' => $this->warningAt,
            'due_at' => $this->dueAt,
        ];

        if ($resetNotifications) {
            $attributes['warning_notified_at'] = null;
            $attributes['breached_notified_at'] = null;
        }

        return $attributes;
    }
}
