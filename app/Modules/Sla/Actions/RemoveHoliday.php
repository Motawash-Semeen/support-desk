<?php

namespace App\Modules\Sla\Actions;

use App\Models\User;
use App\Modules\Sla\Models\Holiday;
use Illuminate\Support\Facades\Log;

final class RemoveHoliday
{
    public function __invoke(User $actor, Holiday $holiday): void
    {
        $holiday->delete();

        Log::info('Holiday removed.', [
            'admin_id' => $actor->id,
            'holiday_id' => $holiday->id,
            'date' => $holiday->date->format('Y-m-d'),
        ]);
    }
}
