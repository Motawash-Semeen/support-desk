<?php

namespace App\Models;

use App\Enums\ActivityType;
use Database\Factories\TicketActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit entry. Rows are inserted, never updated; a NULL actor
 * means the system performed the change.
 */
class TicketActivity extends Model
{
    /** @use HasFactory<TicketActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
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

    /**
     * Append-only: rows are created, never changed or removed through the
     * model. (They disappear only by the database cascade when their ticket
     * is deleted.) There are no update or delete routes.
     */
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

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isSystem(): bool
    {
        return $this->actor_id === null;
    }
}
