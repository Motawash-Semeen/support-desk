<?php

namespace Database\Factories;

use App\Modules\Sla\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Dates 60+ days ahead, so a holiday never lands in a test's SLA window by accident. */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'date' => now()->addDays(fake()->unique()->numberBetween(60, 420))->format('Y-m-d'),
            'name' => ucfirst(fake()->words(2, true)).' Day',
        ];
    }
}
