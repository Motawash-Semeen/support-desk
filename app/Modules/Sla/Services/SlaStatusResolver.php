<?php

namespace App\Modules\Sla\Services;

use App\Modules\Sla\Enums\SlaStatus;
use App\Modules\Tickets\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for SLA status. resolve() (PHP, one ticket) and
 * applyScope() (SQL, many tickets) encode the same comparisons and sit side
 * by side; a test asserts they agree on every boundary. Only comparisons:
 * the business-hours maths already happened when due_at was stored.
 *
 *   active, due_at <  now                           -> breached
 *   active, due_at >= now, sla_warning_at <= now    -> approaching
 *   active, due_at >= now, warning null or > now    -> within_sla
 *   finished, COALESCE(resolved_at, closed_at) <= due_at -> met, else missed
 *   no due_at (or finished with no finish time)     -> null
 */
final class SlaStatusResolver
{
    public static function resolve(
        ?CarbonInterface $dueAt,
        ?CarbonInterface $warningAt,
        ?CarbonInterface $finishedAt,
        bool $isActive,
        CarbonInterface $now,
    ): ?SlaStatus {
        if ($dueAt === null) {
            return null;
        }

        if (! $isActive) {
            if ($finishedAt === null) {
                return null;
            }

            return $finishedAt->lte($dueAt) ? SlaStatus::Met : SlaStatus::Missed;
        }

        if ($dueAt->lt($now)) {
            return SlaStatus::Breached;
        }

        if ($warningAt !== null && $warningAt->lte($now)) {
            return SlaStatus::Approaching;
        }

        return SlaStatus::WithinSla;
    }

    /**
     * The same rules as resolve(), as WHERE clauses on the indexed columns
     * (status, due_at) and (status, sla_warning_at).
     */
    public static function applyScope(Builder $query, SlaStatus $status, CarbonInterface $now): Builder
    {
        $query->whereNotNull('due_at');

        return match ($status) {
            SlaStatus::Breached => $query
                ->whereIn('status', TicketStatus::activeValues())
                ->where('due_at', '<', $now),
            SlaStatus::Approaching => $query
                ->whereIn('status', TicketStatus::activeValues())
                ->where('due_at', '>=', $now)
                ->whereNotNull('sla_warning_at')
                ->where('sla_warning_at', '<=', $now),
            SlaStatus::WithinSla => $query
                ->whereIn('status', TicketStatus::activeValues())
                ->where('due_at', '>=', $now)
                ->where(fn (Builder $q) => $q->whereNull('sla_warning_at')->orWhere('sla_warning_at', '>', $now)),
            SlaStatus::Met => $query
                ->whereIn('status', TicketStatus::finishedValues())
                ->whereRaw('COALESCE(resolved_at, closed_at) <= due_at'),
            SlaStatus::Missed => $query
                ->whereIn('status', TicketStatus::finishedValues())
                ->whereRaw('COALESCE(resolved_at, closed_at) > due_at'),
        };
    }
}
