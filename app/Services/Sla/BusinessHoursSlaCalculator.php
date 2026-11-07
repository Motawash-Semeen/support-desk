<?php

namespace App\Services\Sla;

use App\Enums\TicketPriority;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Resolution target in business minutes:
 *   warningAt = start + round(minutes x warning_threshold) business minutes
 *   dueAt     = start + minutes business minutes
 * Intermediate maths runs in the business timezone (BusinessCalendar);
 * results are UTC instants.
 */
final class BusinessHoursSlaCalculator implements SlaCalculator
{
    public function __construct(private readonly SlaRules $rules) {}

    public function calculate(TicketPriority $priority, CarbonInterface $from): SlaResult
    {
        $policy = $this->rules->policyFor($priority);
        $calendar = $this->rules->calendar();
        $start = CarbonImmutable::instance($from);

        $warningMinutes = (int) round($policy->minutes * $policy->warningThreshold);

        return new SlaResult(
            policyId: $policy->id,
            minutes: $policy->minutes,
            warningAt: $calendar->addBusinessMinutes($start, $warningMinutes)->utc(),
            dueAt: $calendar->addBusinessMinutes($start, $policy->minutes)->utc(),
        );
    }
}
