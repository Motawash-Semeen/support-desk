<?php

use App\Enums\TicketPriority;
use App\Services\Sla\BusinessHoursSlaCalculator;
use App\Services\Sla\SlaPolicyRule;
use Carbon\CarbonImmutable;
use Tests\Support\SlaFixtures as F;

function calculator(array $policies = [], array $holidays = []): BusinessHoursSlaCalculator
{
    return new BusinessHoursSlaCalculator(new F(F::calendarWith($holidays), $policies));
}

it('computes the worked example as UTC instants', function () {
    // Thursday 17:00 Dhaka = 11:00 UTC.
    $result = calculator()->calculate(TicketPriority::Critical, CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));

    expect($result->minutes)->toBe(120)
        ->and($result->dueAt->getTimezone()->getName())->toBe('UTC')
        ->and($result->dueAt->format('Y-m-d H:i'))->toBe('2026-11-08 04:00') // Sunday 10:00 Dhaka
        ->and($result->dueAt->setTimezone('Asia/Dhaka')->format('D H:i'))->toBe('Sun 10:00')
        // warning = round(120 x 0.8) = 96 min: 60 on Thursday + 36 on Sunday = Sunday 09:36 Dhaka
        ->and($result->warningAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-08 09:36');
});

it('accepts a start in any timezone and gives the same instant', function () {
    $fromUtc = calculator()->calculate(TicketPriority::High, CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));
    $fromLocal = calculator()->calculate(TicketPriority::High, F::local('2026-11-05 17:00:00'));

    expect($fromUtc->dueAt->equalTo($fromLocal->dueAt))->toBeTrue();
});

it('uses each priority target', function (TicketPriority $priority, string $expectedLocal) {
    $result = calculator()->calculate($priority, F::local('2026-11-08 09:00'));

    expect($result->dueAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe($expectedLocal);
})->with([
    'critical 120' => [TicketPriority::Critical, '2026-11-08 11:00'],
    'high 480' => [TicketPriority::High, '2026-11-08 17:00'],
    'medium 1440' => [TicketPriority::Medium, '2026-11-10 15:00'],
    'low 2880' => [TicketPriority::Low, '2026-11-15 12:00'],
]);

it('spans a holiday for a low-priority ticket', function () {
    $result = calculator(holidays: ['2026-11-10'])->calculate(TicketPriority::Low, F::local('2026-11-08 09:00'));

    expect($result->dueAt->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-16 12:00');
});

it('uses the policy id, minutes and threshold from the rules', function () {
    $result = calculator(['high' => new SlaPolicyRule(7, 130, 0.75)])
        ->calculate(TicketPriority::High, F::local('2026-11-08 09:00'));

    expect($result->policyId)->toBe(7)
        ->and($result->minutes)->toBe(130)
        // round(130 x 0.75) = round(97.5) = 98 minutes
        ->and($result->warningAt->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('10:38')
        ->and($result->dueAt->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('11:10');
});

it('maps to ticket columns, clearing notification markers only when asked', function () {
    $result = calculator()->calculate(TicketPriority::Critical, F::local('2026-11-08 09:00'));

    expect($result->toTicketAttributes())->toHaveKeys(['sla_policy_id', 'sla_minutes', 'sla_warning_at', 'due_at', 'warning_notified_at', 'breached_notified_at'])
        ->and($result->toTicketAttributes(resetNotifications: false))->not->toHaveKey('warning_notified_at');
});
