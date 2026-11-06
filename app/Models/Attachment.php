<?php

namespace App\Models;

use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File metadata. The file itself lives on a private disk under stored_name
 * (UUID-based) and is streamed through an authorized controller action.
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id',
        'ticket_reply_id',
        'uploaded_by',
        'original_name',
        'stored_name',
        'mime_type',
        'size_bytes',
        'checksum',
        'disk',
    ];

    /** @var list<string> */
    protected $hidden = [
        'stored_name',
        'disk',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TicketReply, $this> */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'ticket_reply_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
