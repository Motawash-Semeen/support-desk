<?php

namespace App\Modules\Sla\Actions;

use App\Models\User;
use App\Modules\Sla\Models\Holiday;
use Illuminate\Support\Facades\Log;

final class AddHoliday
{
    /**
     * @param  array{date: string, name: string}  $data
     */
    public function __invoke(User $actor, array $data): Holiday
    {
        $holiday = Holiday::query()->create([
            'date' => $data['date'],
            'name' => $data['name'],
        ]);

        Log::info('Holiday added.', ['admin_id' => $actor->id, 'holiday_id' => $holiday->id, 'date' => $data['date']]);

        return $holiday;
    }
}
