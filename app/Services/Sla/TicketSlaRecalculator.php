<?php

namespace App\Services\Sla;

use App\Enums\ActivityType;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketActivityRecorder;
use App\Support\BusinessTime;
use Carbon\CarbonInterface;

/**
 * Re-applies the current SLA rules to one ticket (priority change, reopen,
 * sla:recalculate). The caller holds the row lock and the transaction and
 * saves the ticket; this class fills the columns and writes the audit entry.
 */
final class TicketSlaRecalculator
{
    public function __construct(
        private readonly SlaCalculator $sla,
        private readonly TicketActivityRecorder $activity,
    ) {}

    /** The new result, or null when the stored snapshot already matches it. */
    public function preview(Ticket $ticket, CarbonInterface $from): ?SlaResult
    {
        $result = $this->sla->calculate($ticket->priority, $from);

        $unchanged = $ticket->sla_minutes === $result->minutes
            && $ticket->sla_policy_id === $result->policyId
            && $ticket->due_at?->equalTo($result->dueAt)
            && $ticket->sla_warning_at?->equalTo($result->warningAt);

        return $unchanged ? null : $result;
    }

    /**
     * Fills sla_policy_id, sla_minutes, sla_warning_at and due_at and records
     * an `sla_recalculated` activity with old and new values. Notification
     * markers are cleared only when the new deadline is still ahead.
     *
     * @return bool false when nothing changed (no activity recorded)
     */
    public function apply(Ticket $ticket, CarbonInterface $from, ?User $actor, string $reason): bool
    {
        $result = $this->preview($ticket, $from);

        if ($result === null) {
            return false;
        }

        $oldDueAt = $ticket->due_at;
        $old = [
            'due_at' => $oldDueAt?->toIso8601String(),
            'sla_warning_at' => $ticket->sla_warning_at?->toIso8601String(),
            'sla_minutes' => $ticket->sla_minutes,
        ];

        $ticket->fill($result->toTicketAttributes(resetNotifications: $result->dueAt->isFuture()));

        $this->activity->record(
            $ticket,
            ActivityType::SlaRecalculated,
            $actor,
            sprintf('SLA recalculated (%s): due %s → %s', $reason, BusinessTime::format($oldDueAt), BusinessTime::format($result->dueAt)),
            $old,
            [
                'due_at' => $result->dueAt->toIso8601String(),
                'sla_warning_at' => $result->warningAt->toIso8601String(),
                'sla_minutes' => $result->minutes,
            ],
        );

        return true;
    }
}
