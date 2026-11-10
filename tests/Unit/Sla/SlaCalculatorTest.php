<?php

use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Enums\TicketPriority;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Tests\Support\SlaFixtures as F;

/*
| SlaService::deadlines() is the pure part of the SLA maths: a calendar, a
| policy and a start time in, warning and due time out. No database, no config.
*/

function deadlines(TicketPriority $priority, CarbonInterface $from, ?array $policy = null, array $holidays = []): array
{
    return SlaService::deadlines(F::calendarWith($holidays), $policy ?? F::policy($priority), $from);
}

it('computes the worked example as UTC instants', function () {
    // Thursday 17:00 Dhaka = 11:00 UTC.
    $result = deadlines(TicketPriority::Critical, CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));

    expect($result['sla_minutes'])->toBe(120)
        ->and($result['due_at']->getTimezone()->getName())->toBe('UTC')
        ->and($result['due_at']->format('Y-m-d H:i'))->toBe('2026-11-08 04:00') // Sunday 10:00 Dhaka
        ->and($result['due_at']->setTimezone('Asia/Dhaka')->format('D H:i'))->toBe('Sun 10:00')
        // warning = round(120 x 0.8) = 96 min: 60 on Thursday + 36 on Sunday = Sunday 09:36 Dhaka
        ->and($result['sla_warning_at']->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-08 09:36');
});

it('accepts a start in any timezone and gives the same instant', function () {
    $fromUtc = deadlines(TicketPriority::High, CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));
    $fromLocal = deadlines(TicketPriority::High, F::local('2026-11-05 17:00:00'));

    expect($fromUtc['due_at']->equalTo($fromLocal['due_at']))->toBeTrue();
});

it('uses each priority target', function (TicketPriority $priority, string $expectedLocal) {
    $result = deadlines($priority, F::local('2026-11-08 09:00'));

    expect($result['due_at']->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe($expectedLocal);
})->with([
    'critical 120' => [TicketPriority::Critical, '2026-11-08 11:00'],
    'high 480' => [TicketPriority::High, '2026-11-08 17:00'],
    'medium 1440' => [TicketPriority::Medium, '2026-11-10 15:00'],
    'low 2880' => [TicketPriority::Low, '2026-11-15 12:00'],
]);

it('spans a holiday for a low-priority ticket', function () {
    $result = deadlines(TicketPriority::Low, F::local('2026-11-08 09:00'), holidays: ['2026-11-10']);

    expect($result['due_at']->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-16 12:00');
});

it('uses the policy id, minutes and threshold', function () {
    $result = deadlines(TicketPriority::High, F::local('2026-11-08 09:00'), F::policy(TicketPriority::High, id: 7, minutes: 130, threshold: 0.75));

    expect($result['sla_policy_id'])->toBe(7)
        ->and($result['sla_minutes'])->toBe(130)
        // round(130 x 0.75) = round(97.5) = 98 minutes
        ->and($result['sla_warning_at']->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('10:38')
        ->and($result['due_at']->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('11:10');
});

it('returns exactly the four SLA columns of a ticket', function () {
    $result = deadlines(TicketPriority::Critical, F::local('2026-11-08 09:00'));

    expect(array_keys($result))->toBe(['sla_policy_id', 'sla_minutes', 'sla_warning_at', 'due_at']);
});
