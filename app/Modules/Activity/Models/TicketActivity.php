<?php

namespace App\Modules\Activity\Models;

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only history entry. A null actor means the system. */
class TicketActivity extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'ticket_id',
        'actor_id',
        'type',
        'description',
        'old_values',
        'new_values',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Rows are never changed or deleted through the model. */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Ticket activities are append-only and cannot be modified.');
        });

        static::deleting(function (): never {
            throw new LogicException('Ticket activities are append-only and cannot be deleted.');
        });
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Ticket activities are append-only and cannot be modified.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Ticket activities are append-only and cannot be deleted.');
    }

    /** Adds one entry to a ticket's history, inside the caller's transaction. */
    public static function record(Ticket $ticket, ActivityType $type, ?User $actor, string $description, array $old = [], array $new = []): self
    {
        return self::query()->create([
            'ticket_id' => $ticket->getKey(),
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'description' => mb_strimwidth($description, 0, 255, '…'),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
        ]);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
