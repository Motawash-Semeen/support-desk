<?php

namespace App\Modules\Sla\Enums;

use App\Modules\Tickets\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * SLA standing: active tickets are within SLA, approaching or breached; finished
 * ones met or missed. resolve() (PHP) and applyTo() (SQL) hold the same rules.
 */
enum SlaStatus: string
{
    case WithinSla = 'within_sla';
    case Approaching = 'approaching';
    case Breached = 'breached';
    case Met = 'met';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::WithinSla => 'Within SLA',
            self::Approaching => 'At risk',
            self::Breached => 'SLA breached',
            self::Met => 'SLA met',
            self::Missed => 'SLA missed',
        };
    }

    /** Tailwind colour name used by the SLA badge. */
    public function color(): string
    {
        return match ($this) {
            self::WithinSla, self::Met => 'green',
            self::Approaching => 'amber',
            self::Breached, self::Missed => 'red',
        };
    }

    /** Status of one ticket; null when it has no deadline. */
    public static function resolve(
        ?CarbonInterface $dueAt,
        ?CarbonInterface $warningAt,
        ?CarbonInterface $finishedAt,
        bool $isActive,
        CarbonInterface $now,
    ): ?self {
        if ($dueAt === null) {
            return null;
        }

        if (! $isActive) {
            if ($finishedAt === null) {
                return null;
            }

            return $finishedAt->lte($dueAt) ? self::Met : self::Missed;
        }

        if ($dueAt->lt($now)) {
            return self::Breached;
        }

        if ($warningAt !== null && $warningAt->lte($now)) {
            return self::Approaching;
        }

        return self::WithinSla;
    }

    /** The same rules as resolve(), as a WHERE on a tickets query. */
    public function applyTo(Builder $query, CarbonInterface $now): Builder
    {
        $query->whereNotNull('due_at');

        return match ($this) {
            self::Breached => $query
                ->whereIn('status', TicketStatus::activeValues())
                ->where('due_at', '<', $now),
            self::Approaching => $query
                ->whereIn('status', TicketStatus::activeValues())
                ->where('due_at', '>=', $now)
                ->whereNotNull('sla_warning_at')
                ->where('sla_warning_at', '<=', $now),
            self::WithinSla => $query
                ->whereIn('status', TicketStatus::activeValues())
                ->where('due_at', '>=', $now)
                ->where(fn (Builder $q) => $q->whereNull('sla_warning_at')->orWhere('sla_warning_at', '>', $now)),
            self::Met => $query
                ->whereIn('status', TicketStatus::finishedValues())
                ->whereRaw('COALESCE(resolved_at, closed_at) <= due_at'),
            self::Missed => $query
                ->whereIn('status', TicketStatus::finishedValues())
                ->whereRaw('COALESCE(resolved_at, closed_at) > due_at'),
        };
    }
}
