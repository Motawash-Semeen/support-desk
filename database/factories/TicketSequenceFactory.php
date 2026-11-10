<?php

namespace Database\Factories;

use App\Modules\Tickets\Models\TicketSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketSequenceFactory extends Factory
{
    protected $model = TicketSequence::class;

    public function definition(): array
    {
        return [
            'year' => (int) now()->year,
            'last_number' => 0,
        ];
    }
}
