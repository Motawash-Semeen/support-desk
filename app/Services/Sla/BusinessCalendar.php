<?php

namespace App\Services\Sla;

use App\Exceptions\NoBusinessHoursConfigured;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Pure, immutable working-time calendar: no database, no config, no clock.
 *
 * Weekdays use Carbon numbering (0 = Sunday ... 6 = Saturday). Opening and
 * closing times are wall-clock times in $timezone. A working window is
 * [opens, closes): the opening minute is working time, the closing minute is
 * not. Holidays are whole calendar days ('Y-m-d') in $timezone.
 *
 * All maths happens in $timezone; results are returned in $timezone (the
 * same instant can be converted with ->utc()).
 */
final class BusinessCalendar
{
    /** Upper bound on days scanned / working days consumed, so bad config cannot loop forever. */
    public const MAX_DAYS = 366;

    /** @var array<int, array{0: string, 1: string}|null> */
    private array $hours = [];

    /** @var array<string, true> */
    private array $holidays = [];

    /**
     * @param  array<int, array{0: string, 1: string}|null>  $weekdayHours  0-6 => ['09:00', '18:00'] or null
     * @param  iterable<string>  $holidays  'Y-m-d' dates in $timezone
     */
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

    /** True when $moment falls inside a working window: opens <= t < closes, not a holiday. */
    public function isWorkingMoment(CarbonInterface $moment): bool
    {
        $local = $this->local($moment);
        $window = $this->window($local);

        return $window !== null && $local->gte($window[0]) && $local->lt($window[1]);
    }

    /**
     * $moment itself when it is working time; today's opening when it is before
     * opening; otherwise the opening of the next working day.
     */
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

        throw NoBusinessHoursConfigured::within(self::MAX_DAYS);
    }

    /**
     * Adds working minutes day by day: consume what is left of the current
     * window, carry the remainder to the next opening. Iterative, with a guard.
     * A deadline that lands exactly on closing time stays on that day.
     */
    public function addBusinessMinutes(CarbonInterface $start, int $minutes): CarbonImmutable
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException('Business minutes must not be negative.');
        }

        $cursor = $this->nextOpening($start);
        $remaining = $minutes * 60; // seconds, so a start of 17:00:30 is kept exact

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $closes = $this->window($cursor)[1];
            $available = $closes->getTimestamp() - $cursor->getTimestamp();

            if ($remaining <= $available) {
                return $cursor->addSeconds($remaining);
            }

            $remaining -= $available;
            $cursor = $this->nextOpening($cursor->addDay()->startOfDay());
        }

        throw NoBusinessHoursConfigured::within(self::MAX_DAYS);
    }

    /**
     * Opening and closing instants for the calendar day of $local, or null
     * when the day is not worked.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
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
}
