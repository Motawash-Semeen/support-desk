<?php

namespace App\Modules\Conversation\Models;

use App\Models\User;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A public reply or an internal note (staff only). */
class TicketReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'type',
        'body',
    ];

    protected $attributes = [
        'type' => 'public',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReplyType::class,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

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
