<?php

namespace Database\Factories;

use App\Models\TicketImport;
use App\Models\TicketImportError;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketImportError>
 */
class TicketImportErrorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_import_id' => TicketImport::factory(),
            'row_number' => fake()->numberBetween(2, 500),
            'raw_data' => ['subject' => '', 'priority' => 'urgent'],
            'errors' => [
                'subject' => ['The subject field is required.'],
                'priority' => ['The selected priority is invalid.'],
            ],
        ];
    }
}
