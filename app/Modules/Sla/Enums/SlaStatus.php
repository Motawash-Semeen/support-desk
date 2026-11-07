<?php

namespace App\Modules\Sla\Enums;

/**
 * SLA standing of a ticket. Active tickets are within_sla / approaching /
 * breached; resolved or closed tickets either met or missed their target.
 * Derived by App\Modules\Sla\Services\SlaStatusResolver (PHP and SQL).
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
}
