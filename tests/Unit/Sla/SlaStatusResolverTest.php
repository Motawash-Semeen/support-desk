<?php

use App\Enums\SlaStatus;
use App\Services\Sla\SlaStatusResolver;
use Carbon\CarbonImmutable;

it('derives SLA status by comparison only', function (?string $due, ?string $warning, ?string $finished, bool $active, ?SlaStatus $expected) {
    $now = CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC');
    $at = fn (?string $t) => $t === null ? null : CarbonImmutable::parse($t, 'UTC');

    expect(SlaStatusResolver::resolve($at($due), $at($warning), $at($finished), $active, $now))->toBe($expected);
})->with([
    'active, before warning' => ['2026-11-08 08:00:00', '2026-11-08 07:00:00', null, true, SlaStatus::WithinSla],
    'active, no warning time' => ['2026-11-08 08:00:00', null, null, true, SlaStatus::WithinSla],
    'active, warning exactly now' => ['2026-11-08 08:00:00', '2026-11-08 06:00:00', null, true, SlaStatus::Approaching],
    'active, past warning' => ['2026-11-08 08:00:00', '2026-11-08 05:00:00', null, true, SlaStatus::Approaching],
    'active, due exactly now (not yet breached)' => ['2026-11-08 06:00:00', '2026-11-08 05:00:00', null, true, SlaStatus::Approaching],
    'active, one second past due' => ['2026-11-08 05:59:59', '2026-11-08 05:00:00', null, true, SlaStatus::Breached],
    'finished before due' => ['2026-11-08 08:00:00', null, '2026-11-08 07:00:00', false, SlaStatus::Met],
    'finished exactly at due' => ['2026-11-08 08:00:00', null, '2026-11-08 08:00:00', false, SlaStatus::Met],
    'finished after due' => ['2026-11-08 05:00:00', null, '2026-11-08 05:30:00', false, SlaStatus::Missed],
    'finished long after due, judged by finish time not now' => ['2026-11-08 05:00:00', null, '2026-11-08 04:00:00', false, SlaStatus::Met],
    'no due date' => [null, null, null, true, null],
    'finished without a finish time' => ['2026-11-08 05:00:00', null, null, false, null],
]);
