<?php

namespace Database\Factories;

use App\Models\TicketSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketSequence>
 */
class TicketSequenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'year' => (int) now()->year,
            'last_number' => 0,
        ];
    }
}
