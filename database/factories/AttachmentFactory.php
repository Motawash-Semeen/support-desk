<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Metadata only; tests that need the file write it with Storage::fake().
 *
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $extension = fake()->randomElement(['pdf', 'png', 'txt']);

        return [
            'ticket_id' => Ticket::factory(),
            'ticket_reply_id' => null,
            'uploaded_by' => User::factory(),
            'original_name' => fake()->slug(3).'.'.$extension,
            'stored_name' => Str::uuid()->toString().'.'.$extension,
            'mime_type' => match ($extension) {
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                default => 'text/plain',
            },
            'size_bytes' => fake()->numberBetween(1_024, 2_000_000),
            'checksum' => hash('sha256', Str::random(32)),
            'disk' => config('support.attachments.disk'),
        ];
    }
}
