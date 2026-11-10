<?php

namespace Tests\Support;

use App\Modules\Sla\BusinessCalendar;
use App\Modules\Tickets\Enums\TicketPriority;
use Carbon\CarbonImmutable;

/**
 * Database-free SLA fixtures for unit tests.
 *
 * Reference week (Asia/Dhaka, Sunday-Thursday 09:00-18:00 = 540 min/day):
 *   Thu 2026-11-05, Fri 11-06 (closed), Sat 11-07 (closed), Sun 11-08,
 *   Mon 11-09, Tue 11-10, Wed 11-11, Thu 11-12, Sun 11-15, Mon 11-16.
 */
final class SlaFixtures
{
    public const TIMEZONE = 'Asia/Dhaka';

    /** Mirrors config/support.php defaults (unit tests have no config). */
    public const DEFAULT_MINUTES = ['low' => 2880, 'medium' => 1440, 'high' => 480, 'critical' => 120];

    /**
     * A policy as SlaService::policyFor() returns it; the config default
     * unless minutes are given.
     */
    public static function policy(TicketPriority $priority, ?int $id = null, ?int $minutes = null, float $threshold = 0.8): array
    {
        return ['id' => $id, 'minutes' => $minutes ?? self::DEFAULT_MINUTES[$priority->value], 'warning_threshold' => $threshold];
    }

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
