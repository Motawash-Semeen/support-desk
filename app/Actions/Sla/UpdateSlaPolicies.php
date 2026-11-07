<?php

namespace App\Actions\Sla;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saves the resolution target per priority. Rows are created when missing,
 * so the screen works on a fresh database. Only new tickets (and explicit
 * sla:recalculate runs) use the new values.
 */
final class UpdateSlaPolicies
{
    /**
     * @param  array<string, array{resolution_minutes: int|string, warning_threshold: float|string, is_active: bool|string}>  $policies  keyed by priority value
     * @return array<string, array<string, mixed>> changed fields per priority
     */
    public function __invoke(User $actor, array $policies): array
    {
        $changes = DB::transaction(function () use ($policies): array {
            $changes = [];

            foreach ($policies as $priority => $values) {
                $policy = SlaPolicy::query()->firstOrNew(['priority' => TicketPriority::from($priority)]);

                $policy->fill([
                    'resolution_minutes' => (int) $values['resolution_minutes'],
                    'warning_threshold' => round((float) $values['warning_threshold'], 2),
                    'is_active' => (bool) $values['is_active'],
                ]);

                if (! $policy->exists || $policy->isDirty()) {
                    $changes[$priority] = $policy->getDirty();
                    $policy->save(); // model save, so the cache observer runs
                }
            }

            return $changes;
        });

        if ($changes !== []) {
            Log::info('SLA policies updated.', ['admin_id' => $actor->id, 'changes' => $changes]);
        }

        return $changes;
    }
}
