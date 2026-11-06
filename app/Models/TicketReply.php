<?php

namespace App\Models;

use App\Enums\ReplyType;
use Database\Factories\TicketReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A public reply or an internal note (see ReplyType). Internal notes are
 * filtered out for customers via Ticket::publicReplies() and the policy.
 */
class TicketReply extends Model
{
    /** @use HasFactory<TicketReplyFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id',
        'user_id',
        'type',
        'body',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'public',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReplyType::class,
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function isInternal(): bool
    {
        return $this->type === ReplyType::InternalNote;
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('type', ReplyType::Public);
    }
}
