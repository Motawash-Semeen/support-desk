<?php

namespace App\Actions\Sla;

use App\Models\Holiday;
use App\Models\User;
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
