<?php

namespace Tests\Support;

use App\Modules\Sla\Services\BusinessCalendar;
use App\Modules\Sla\Services\SlaPolicyRule;
use App\Modules\Sla\Services\SlaRules;
use App\Modules\Tickets\Enums\TicketPriority;
use Carbon\CarbonImmutable;

/**
 * Database-free SLA fixtures for unit tests.
 *
 * Reference week (Asia/Dhaka, Sunday-Thursday 09:00-18:00 = 540 min/day):
 *   Thu 2026-11-05, Fri 11-06 (closed), Sat 11-07 (closed), Sun 11-08,
 *   Mon 11-09, Tue 11-10, Wed 11-11, Thu 11-12, Sun 11-15, Mon 11-16.
 */
final class SlaFixtures implements SlaRules
{
    public const TIMEZONE = 'Asia/Dhaka';

    /** Mirrors config/support.php defaults (unit tests have no config). */
    public const DEFAULT_MINUTES = ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120];

    /**
     * @param  array<string, SlaPolicyRule>  $policies  keyed by priority value
     */
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly array $policies = [],
    ) {}

    public function policyFor(TicketPriority $priority): SlaPolicyRule
    {
        return $this->policies[$priority->value]
            ?? new SlaPolicyRule(null, self::DEFAULT_MINUTES[$priority->value], 0.8);
    }

    public function calendar(): BusinessCalendar
    {
        return $this->calendar;
    }

    /**
     * @param  list<string>  $holidays
     * @param  array<int, array{0: string, 1: string}|null>|null  $hours
     */
    public static function calendarWith(array $holidays = [], ?array $hours = null): BusinessCalendar
    {
        $standard = ['09:00', '18:00'];

        return new BusinessCalendar(
            $hours ?? [0 => $standard, 1 => $standard, 2 => $standard, 3 => $standard, 4 => $standard, 5 => null, 6 => null],
            $holidays,
            self::TIMEZONE,
        );
    }

    /** A wall-clock time in the business timezone. */
    public static function local(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, self::TIMEZONE);
    }
}
