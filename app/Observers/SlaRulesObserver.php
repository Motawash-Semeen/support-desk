<?php

namespace App\Observers;

use App\Services\Sla\SlaPolicyRepository;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Attached to SlaPolicy, BusinessHour and Holiday. Flushes the cached SLA
 * rules after the change commits, so no request can re-cache the old rows
 * between the flush and the commit.
 *
 * Bulk query-builder updates bypass model events; the admin Actions save
 * models one by one for this reason.
 */
class SlaRulesObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly SlaPolicyRepository $rules) {}

    public function saved(): void
    {
        $this->rules->flush();
    }

    public function deleted(): void
    {
        $this->rules->flush();
    }
}
