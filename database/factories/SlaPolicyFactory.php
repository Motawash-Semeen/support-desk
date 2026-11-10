<?php

namespace Database\Factories;

use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use Illuminate\Database\Eloquent\Factories\Factory;

class SlaPolicyFactory extends Factory
{
    protected $model = SlaPolicy::class;

    /** Picks an unused priority (it is unique); forPriority() pins one. */
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
