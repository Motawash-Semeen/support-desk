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

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Ticket activities are append-only and cannot be modified.');
        });
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
