<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** Metadata only; tests that need the file write it with Storage::fake(). */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        $extension = fake()->randomElement(['pdf', 'png', 'txt']);

        return [
            'ticket_id' => Ticket::factory(),
            'ticket_reply_id' => null,
            'uploaded_by' => User::factory(),
            'original_name' => fake()->slug(3).'.'.$extension,
            // Same layout Attachment::storeUploads() writes: tickets/{ticket_id}/{Y}/{m}/{uuid}.{ext}
            'stored_name' => fn (array $a) => sprintf('tickets/%d/%s/%s.%s', $a['ticket_id'], now()->format('Y/m'), Str::uuid(), $extension),
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
