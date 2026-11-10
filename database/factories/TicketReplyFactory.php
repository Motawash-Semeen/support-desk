<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketReplyFactory extends Factory
{
    protected $model = TicketReply::class;

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
