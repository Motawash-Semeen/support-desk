<?php

namespace Database\Factories;

use App\Modules\Sla\Models\BusinessHour;
use Illuminate\Database\Eloquent\Factories\Factory;

class BusinessHourFactory extends Factory
{
    protected $model = BusinessHour::class;

    public function definition(): array
    {
        return [
            'weekday' => fake()->unique()->numberBetween(0, 6),
            'is_working_day' => true,
            'opens_at' => config('support.business_hours.start').':00',
            'closes_at' => config('support.business_hours.end').':00',
        ];
    }

    public function closed(): static
    {
        return $this->state([
            'is_working_day' => false,
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }
}
