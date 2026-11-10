<?php

namespace App\Modules\Conversation\Models;

use App\Models\User;
use App\Modules\Tickets\Models\Ticket;
use App\Support\SafeFilename;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** File metadata. The file is on a private disk under a UUID name. */
class Attachment extends Model
{
    use HasFactory;

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

    /**
     * Stores validated uploads on the private disk as tickets/{ticket}/{Y}/{m}/{uuid}.{ext}
     * and records them. Call it inside the caller's transaction: files written here
     * are removed if this fails, and the caller calls deleteFiles() if its own
     * transaction rolls back.
     */
    public static function storeUploads(Ticket $ticket, ?TicketReply $reply, User $uploader, array $files): Collection
    {
        $diskName = (string) config('support.attachments.disk');
        $disk = Storage::disk($diskName);
        $written = [];
        $attachments = collect();

        try {
            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $directory = sprintf('tickets/%d/%s', $ticket->getKey(), now()->format('Y/m'));
                $path = $disk->putFileAs($directory, $file, Str::uuid()->toString().'.'.$extension);

                if ($path === false) {
                    throw new RuntimeException("Could not store attachment on disk [{$diskName}].");
                }

                $written[] = $path;

                $attachments->push(self::query()->create([
                    'ticket_id' => $ticket->getKey(),
                    'ticket_reply_id' => $reply?->getKey(),
                    'uploaded_by' => $uploader->getKey(),
                    'original_name' => SafeFilename::clean($file->getClientOriginalName()),
                    'stored_name' => $path,
                    'mime_type' => (string) $file->getMimeType(), // from the content, not the client
                    'size_bytes' => (int) $file->getSize(),
                    'checksum' => hash_file('sha256', $file->getRealPath()),
                    'disk' => $diskName,
                ]));
            }
        } catch (Throwable $e) {
            $disk->delete($written);

            throw $e;
        }

        return $attachments;
    }

    /** Removes the files behind attachments whose transaction did not commit. */
    public static function deleteFiles(Collection $attachments): void
    {
        $attachments->groupBy('disk')->each(
            fn (Collection $group, string $disk) => Storage::disk($disk)->delete($group->pluck('stored_name')->all())
        );
    }

    /** Path on the disk; never sent to the client. */
    public function path(): string
    {
        return $this->stored_name;
    }

    /** Download name: the cleaned original name. */
    public function downloadName(): string
    {
        return SafeFilename::clean($this->original_name);
    }

    /** Only these types may be shown inline (images); everything else downloads. */
    public function canDisplayInline(): bool
    {
        return in_array($this->mime_type, ['image/png', 'image/jpeg'], true);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'ticket_reply_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
