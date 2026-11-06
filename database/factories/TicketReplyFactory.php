<?php

namespace Database\Factories;

use App\Enums\ReplyType;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketReply>
 */
class TicketReplyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'type' => ReplyType::Public,
            'body' => fake()->paragraphs(2, true),
        ];
    }

    /** Staff-only note written by an agent. */
    public function internalNote(): static
    {
        return $this->state(fn (array $a) => [
            'type' => ReplyType::InternalNote,
            'user_id' => $a['user_id'] instanceof Factory ? User::factory()->agent() : $a['user_id'],
        ]);
    }
}
