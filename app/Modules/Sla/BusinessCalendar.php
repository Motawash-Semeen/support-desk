<?php

namespace App\Modules\Sla;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pure working-time calendar: no database, config or clock. Weekdays are
 * 0 = Sunday ... 6 = Saturday; hours and holidays are in $timezone. A window is
 * [opens, closes): the closing minute is not working time.
 */
final class BusinessCalendar
{
    /** Most days scanned, so a bad configuration can't loop forever. */
    public const MAX_DAYS = 366;

    private array $hours = [];

    private array $holidays = [];

    public function __construct(array $weekdayHours, iterable $holidays, private readonly string $timezone)
    {
        foreach (range(0, 6) as $weekday) {
            $window = $weekdayHours[$weekday] ?? null;

            if ($window !== null) {
                [$opens, $closes] = [self::normaliseTime($window[0]), self::normaliseTime($window[1])];

                if ($closes <= $opens) {
                    throw new InvalidArgumentException("Weekday {$weekday} closes ({$closes}) before it opens ({$opens}).");
                }

                $window = [$opens, $closes];
            }

            $this->hours[$weekday] = $window;
        }

        foreach ($holidays as $date) {
            $this->holidays[$date] = true;
        }
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /** True inside a working window (opens <= t < closes) on a non-holiday. */
    public function isWorkingMoment(CarbonInterface $moment): bool
    {
        $local = $this->local($moment);
        $window = $this->window($local);

        return $window !== null && $local->gte($window[0]) && $local->lt($window[1]);
    }

    /** $moment if it is working time, otherwise the next opening. */
    public function nextOpening(CarbonInterface $moment): CarbonImmutable
    {
        $local = $this->local($moment);
        $day = $local->startOfDay();

        for ($i = 0; $i < self::MAX_DAYS; $i++, $day = $day->addDay()) {
            $window = $this->window($day);

            if ($window === null) {
                continue;
            }

            [$opens, $closes] = $window;

            if ($local->lt($opens)) {
                return $opens;
            }

            if ($local->lt($closes)) {
                return $local;
            }
        }

        throw self::noWorkingTime();
    }

    /** Adds working minutes: use what is left of today, carry the rest to the next opening. */
    public function addBusinessMinutes(CarbonInterface $start, int $minutes): CarbonImmutable
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException('Business minutes must not be negative.');
        }

        $cursor = $this->nextOpening($start);
        $remaining = $minutes * 60; // seconds, so 17:00:30 stays exact

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $closes = $this->window($cursor)[1];
            $available = $closes->getTimestamp() - $cursor->getTimestamp();

            if ($remaining <= $available) {
                return $cursor->addSeconds($remaining);
            }

            $remaining -= $available;
            $cursor = $this->nextOpening($cursor->addDay()->startOfDay());
        }

        throw self::noWorkingTime();
    }

    /** That day's opening and closing time, or null when it isn't worked. */
    private function window(CarbonImmutable $local): ?array
    {
        $day = $local->startOfDay();
        $hours = $this->hours[$day->dayOfWeek];

        if ($hours === null || isset($this->holidays[$day->format('Y-m-d')])) {
            return null;
        }

        return [$day->setTimeFromTimeString($hours[0]), $day->setTimeFromTimeString($hours[1])];
    }

    private function local(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($this->timezone);
    }

    private static function normaliseTime(string $time): string
    {
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
            throw new InvalidArgumentException("Invalid time '{$time}', expected HH:MM.");
        }

        return substr($time, 0, 5);
    }

    /** No working time within MAX_DAYS: a configuration error. */
    private static function noWorkingTime(): RuntimeException
    {
        return new RuntimeException('No business hours found within '.self::MAX_DAYS.' days. Check the business hours and holidays configuration.');
    }
}
