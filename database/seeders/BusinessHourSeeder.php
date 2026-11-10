<?php

namespace Database\Seeders;

use App\Modules\Sla\Models\BusinessHour;
use Illuminate\Database\Seeder;

/** One row per weekday from config/support.php (default Sunday-Thursday 09:00-18:00). */
class BusinessHourSeeder extends Seeder
{
    public function run(): void
    {
        $workingDays = config('support.business_hours.days');
        $opensAt = config('support.business_hours.start').':00';
        $closesAt = config('support.business_hours.end').':00';

        foreach (range(0, 6) as $weekday) {
            $isWorkingDay = in_array($weekday, $workingDays, true);

            BusinessHour::query()->updateOrCreate(
                ['weekday' => $weekday],
                [
                    'is_working_day' => $isWorkingDay,
                    'opens_at' => $isWorkingDay ? $opensAt : null,
                    'closes_at' => $isWorkingDay ? $closesAt : null,
                ],
            );
        }
    }
}
