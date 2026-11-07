<?php

namespace Database\Factories;

use App\Modules\Sla\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default dates are 60+ days ahead of the (frozen) clock, so a holiday made
 * without an explicit date can never fall inside a test's SLA window.
 *
 * @extends Factory<Holiday>
 */
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
