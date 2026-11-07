<?php

namespace Database\Factories;

use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketActivity>
 */
class TicketActivityFactory extends Factory
{
    protected $model = TicketActivity::class;

    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'actor_id' => null,
            'type' => ActivityType::TicketCreated,
            'description' => 'Ticket created',
            'old_values' => null,
            'new_values' => null,
        ];
    }
}
