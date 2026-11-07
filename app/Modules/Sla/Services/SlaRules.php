<?php

namespace App\Modules\Sla\Services;

use App\Modules\Tickets\Enums\TicketPriority;

/**
 * Where SLA rules come from. Production: SlaPolicyRepository (tables + cache).
 * Unit tests pass an in-memory implementation, so the calculator is tested
 * without a database.
 */
interface SlaRules
{
    public function policyFor(TicketPriority $priority): SlaPolicyRule;

    public function calendar(): BusinessCalendar;
}
