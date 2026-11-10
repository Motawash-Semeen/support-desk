<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\TicketImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TicketImportFactory extends Factory
{
    protected $model = TicketImport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'original_name' => fake()->slug(2).'.csv',
            'path' => 'imports/'.Str::uuid()->toString().'.csv',
            'status' => ImportStatus::Pending,
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
            'last_processed_row' => 0,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function completed(int $success = 10, int $failed = 0): static
    {
        return $this->state([
            'status' => ImportStatus::Completed,
            'total_rows' => $success + $failed,
            'processed_rows' => $success + $failed,
            'success_rows' => $success,
            'failed_rows' => $failed,
            'last_processed_row' => $success + $failed,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }
}
