<?php

use App\Exceptions\NoBusinessHoursConfigured;
use App\Services\Sla\BusinessCalendar;
use Carbon\CarbonImmutable;
use Tests\Support\SlaFixtures as F;

/*
| Pure tests: no database, no config, no clock. See SlaFixtures for the
| reference week (Sunday-Thursday 09:00-18:00, Asia/Dhaka).
*/

/* isWorkingMoment ----------------------------------------------------- */

it('knows which moments are working time', function (string $local, bool $working) {
    expect(F::calendarWith()->isWorkingMoment(F::local($local)))->toBe($working);
})->with([
    'Thursday 09:00 (opening minute)' => ['2026-11-05 09:00:00', true],
    'Thursday 17:59:59' => ['2026-11-05 17:59:59', true],
    'Thursday 18:00 (closing minute is not working)' => ['2026-11-05 18:00:00', false],
    'Thursday 08:59' => ['2026-11-05 08:59:00', false],
    'Friday midday (weekend)' => ['2026-11-06 12:00:00', false],
    'Saturday midday (weekend)' => ['2026-11-07 12:00:00', false],
    'Sunday 10:00' => ['2026-11-08 10:00:00', true],
]);

it('treats a holiday as a non-working day', function () {
    $calendar = F::calendarWith(holidays: ['2026-11-10']);

    expect($calendar->isWorkingMoment(F::local('2026-11-10 12:00')))->toBeFalse()
        ->and($calendar->isWorkingMoment(F::local('2026-11-11 12:00')))->toBeTrue();
});

it('evaluates UTC input in the business timezone', function () {
    // 2026-11-05 03:30 UTC = Thursday 09:30 in Dhaka (UTC+6); 02:59 UTC = 08:59.
    expect(F::calendarWith()->isWorkingMoment(CarbonImmutable::parse('2026-11-05 03:30:00', 'UTC')))->toBeTrue()
        ->and(F::calendarWith()->isWorkingMoment(CarbonImmutable::parse('2026-11-05 02:59:00', 'UTC')))->toBeFalse();
});

/* nextOpening --------------------------------------------------------- */

it('finds the next opening', function (string $from, string $expected) {
    expect(F::calendarWith()->nextOpening(F::local($from))->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'inside hours returns the same moment' => ['2026-11-05 12:34:56', '2026-11-05 12:34:56'],
    'before opening returns today 09:00' => ['2026-11-08 08:00:00', '2026-11-08 09:00:00'],
    'at closing jumps to the next working day' => ['2026-11-05 18:00:00', '2026-11-08 09:00:00'],
    'after closing jumps to the next working day' => ['2026-11-05 22:15:00', '2026-11-08 09:00:00'],
    'Friday jumps to Sunday' => ['2026-11-06 10:00:00', '2026-11-08 09:00:00'],
    'Saturday jumps to Sunday' => ['2026-11-07 23:59:59', '2026-11-08 09:00:00'],
    'Monday evening jumps to Tuesday' => ['2026-11-09 19:00:00', '2026-11-10 09:00:00'],
]);

it('skips holidays when looking for the next opening', function () {
    $calendar = F::calendarWith(holidays: ['2026-11-08', '2026-11-09']);

    expect($calendar->nextOpening(F::local('2026-11-05 18:00'))->format('Y-m-d H:i'))->toBe('2026-11-10 09:00');
});

it('returns results in the business timezone for any input timezone', function () {
    // Wednesday 2026-11-04 20:00 UTC = Thursday 02:00 Dhaka -> opens Thursday 09:00 Dhaka.
    $opening = F::calendarWith()->nextOpening(CarbonImmutable::parse('2026-11-04 20:00:00', 'UTC'));

    expect($opening->getTimezone()->getName())->toBe('Asia/Dhaka')
        ->and($opening->format('Y-m-d H:i'))->toBe('2026-11-05 09:00')
        ->and($opening->utc()->format('Y-m-d H:i'))->toBe('2026-11-05 03:00');
});

/* addBusinessMinutes: the cases the brief pins down ------------------- */

it('carries the worked example over the weekend: Thursday 17:00 + 120 = Sunday 10:00, not Thursday 19:00', function () {
    $due = F::calendarWith()->addBusinessMinutes(F::local('2026-11-05 17:00'), 120);

    expect($due->format('Y-m-d H:i'))->toBe('2026-11-08 10:00')
        ->and($due->format('Y-m-d H:i'))->not->toBe('2026-11-05 19:00')
        ->and($due->dayName)->toBe('Sunday');
});

it('adds business minutes', function (string $start, int $minutes, string $expected) {
    expect(F::calendarWith()->addBusinessMinutes(F::local($start), $minutes)->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'created Friday 10:00 counts from Sunday 09:00' => ['2026-11-06 10:00:00', 120, '2026-11-08 11:00:00'],
    'created Sunday 08:00 counts from 09:00' => ['2026-11-08 08:00:00', 120, '2026-11-08 11:00:00'],
    'created exactly at closing counts from next opening' => ['2026-11-05 18:00:00', 120, '2026-11-08 11:00:00'],
    'ends exactly at closing stays on that day (18:00, not next 09:00)' => ['2026-11-05 10:00:00', 480, '2026-11-05 18:00:00'],
    'ends exactly at closing after crossing a day' => ['2026-11-08 09:00:00', 1080, '2026-11-09 18:00:00'],
    'within one day' => ['2026-11-09 12:15:00', 120, '2026-11-09 14:15:00'],
    'medium (1440) spans three days' => ['2026-11-08 09:00:00', 1440, '2026-11-10 15:00:00'],
    'low (2880) from Sunday opening' => ['2026-11-08 09:00:00', 2880, '2026-11-15 12:00:00'],
    'low (2880) from Thursday 17:00 crosses two weekends' => ['2026-11-05 17:00:00', 2880, '2026-11-15 11:00:00'],
    'zero minutes snaps to the next opening' => ['2026-11-06 10:00:00', 0, '2026-11-08 09:00:00'],
    'keeps seconds' => ['2026-11-05 17:00:30', 120, '2026-11-08 10:00:30'],
]);

it('skips a holiday inside a long SLA: low (2880) with Tuesday off ends Monday 12:00', function () {
    $calendar = F::calendarWith(holidays: ['2026-11-10']);

    // Sun 540 + Mon 540 + (Tue holiday) + Wed 540 + Thu 540 = 2160; next Sun 540 = 2700; Mon +180 = 12:00.
    expect($calendar->addBusinessMinutes(F::local('2026-11-08 09:00'), 2880)->format('Y-m-d H:i'))->toBe('2026-11-16 12:00');
});

it('honours different hours per weekday', function () {
    $calendar = F::calendarWith(hours: [
        0 => ['09:00', '18:00'], 1 => ['09:00', '18:00'], 2 => ['09:00', '18:00'],
        3 => ['09:00', '18:00'], 4 => ['09:00', '13:00'], 5 => null, 6 => null,
    ]);

    // Thursday closes at 13:00: 12:00 + 120 = 60 Thursday + 60 Sunday.
    expect($calendar->addBusinessMinutes(F::local('2026-11-05 12:00'), 120)->format('Y-m-d H:i'))->toBe('2026-11-08 10:00');
});

it('rejects negative minutes', function () {
    expect(fn () => F::calendarWith()->addBusinessMinutes(F::local('2026-11-05 10:00'), -1))
        ->toThrow(InvalidArgumentException::class);
});

/* Guards and configuration errors ------------------------------------- */

it('throws instead of looping forever when no day is worked', function () {
    $calendar = new BusinessCalendar(array_fill(0, 7, null), [], 'Asia/Dhaka');

    expect(fn () => $calendar->nextOpening(F::local('2026-11-05 10:00')))->toThrow(NoBusinessHoursConfigured::class)
        ->and(fn () => $calendar->addBusinessMinutes(F::local('2026-11-05 10:00'), 60))->toThrow(NoBusinessHoursConfigured::class);
});

it('throws when the remaining minutes need more than the guard allows', function () {
    // One working minute per day: 400 minutes need 400 working days > 366.
    $calendar = new BusinessCalendar(array_fill(0, 7, ['09:00', '09:01']), [], 'Asia/Dhaka');

    expect(fn () => $calendar->addBusinessMinutes(F::local('2026-11-05 09:00'), 400))->toThrow(NoBusinessHoursConfigured::class);
});

it('rejects a day that closes before it opens and malformed times', function () {
    expect(fn () => new BusinessCalendar([0 => ['18:00', '09:00']], [], 'Asia/Dhaka'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BusinessCalendar([0 => ['9am', '18:00']], [], 'Asia/Dhaka'))->toThrow(InvalidArgumentException::class);
});

it('accepts database time strings with seconds', function () {
    $calendar = new BusinessCalendar([0 => ['09:00:00', '18:00:00']], [], 'Asia/Dhaka');

    expect($calendar->isWorkingMoment(F::local('2026-11-08 09:00')))->toBeTrue();
});
