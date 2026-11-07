<?php

namespace Database\Seeders;

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use Illuminate\Database\Seeder;

/**
 * One SLA policy per priority, initialised from config/support.php.
 * Idempotent: re-running restores the configured defaults.
 */
class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach (TicketPriority::cases() as $priority) {
            SlaPolicy::query()->updateOrCreate(
                ['priority' => $priority],
                [
                    'resolution_minutes' => $priority->defaultSlaMinutes(),
                    'warning_threshold' => (float) config('support.sla.warning_threshold'),
                    'is_active' => true,
                ],
            );
        }
    }
}
