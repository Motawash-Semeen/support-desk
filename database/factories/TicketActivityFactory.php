<?php

namespace Database\Factories;

use App\Enums\ActivityType;
use App\Models\Ticket;
use App\Models\TicketActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketActivity>
 */
class TicketActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'actor_id' => null,
            'type' => ActivityType::Created,
            'description' => 'Ticket created',
            'old_values' => null,
            'new_values' => null,
        ];
    }
}
