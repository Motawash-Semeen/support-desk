<?php

namespace App\Modules\Sla\Services;

use App\Modules\Tickets\Enums\TicketPriority;
use Carbon\CarbonInterface;

/**
 * Computes the resolution target for a priority, counted from $from.
 * The only entry point the rest of the app uses for SLA maths.
 */
interface SlaCalculator
{
    public function calculate(TicketPriority $priority, CarbonInterface $from): SlaResult;
}
