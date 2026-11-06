<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaPolicy>
 */
class SlaPolicyFactory extends Factory
{
    /**
     * priority is unique in the table, so the default picks a priority not yet
     * used in this test; use forPriority() to pin one.
     */
    public function definition(): array
    {
        $priority = TicketPriority::from(
            fake()->unique()->randomElement(array_column(TicketPriority::cases(), 'value'))
        );

        return [
            'priority' => $priority,
            'resolution_minutes' => $priority->defaultSlaMinutes(),
            'warning_threshold' => (float) config('support.sla.warning_threshold'),
            'is_active' => true,
        ];
    }

    public function forPriority(TicketPriority $priority): static
    {
        return $this->state([
            'priority' => $priority,
            'resolution_minutes' => $priority->defaultSlaMinutes(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
