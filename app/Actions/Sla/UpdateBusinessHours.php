<?php

namespace App\Actions\Sla;

use App\Models\BusinessHour;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saves the weekly schedule (one row per weekday, wall-clock times in the
 * business timezone). Non-working days store no times.
 */
final class UpdateBusinessHours
{
    /**
     * @param  array<int|string, array{is_working_day: bool|string, opens_at?: string|null, closes_at?: string|null}>  $days  keyed 0 (Sunday) .. 6
     */
    public function __invoke(User $actor, array $days): void
    {
        $changes = DB::transaction(function () use ($days): array {
            $changes = [];

            foreach ($days as $weekday => $values) {
                $working = (bool) $values['is_working_day'];
                $day = BusinessHour::query()->firstOrNew(['weekday' => (int) $weekday]);

                $day->fill([
                    'is_working_day' => $working,
                    'opens_at' => $working ? $values['opens_at'].':00' : null,
                    'closes_at' => $working ? $values['closes_at'].':00' : null,
                ]);

                if (! $day->exists || $day->isDirty()) {
                    $changes[$weekday] = $day->getDirty();
                    $day->save();
                }
            }

            return $changes;
        });

        if ($changes !== []) {
            Log::info('Business hours updated.', ['admin_id' => $actor->id, 'changes' => $changes]);
        }
    }
}
