<?php

namespace Database\Factories;

use App\Modules\Imports\Models\TicketImport;
use App\Modules\Imports\Models\TicketImportError;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketImportErrorFactory extends Factory
{
    protected $model = TicketImportError::class;

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
