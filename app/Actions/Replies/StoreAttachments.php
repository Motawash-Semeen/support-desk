<?php

namespace App\Actions\Replies;

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Support\SafeFilename;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Writes validated uploads to the PRIVATE disk and records their metadata.
 *
 *   tickets/{ticket_id}/{Y}/{m}/{uuid}.{ext}
 *
 * The stored name is a server-generated UUID (no traversal, no overwrite, no
 * enumeration by name); the client's name is kept only in the database,
 * cleaned. Called inside the caller's transaction: if a later file or DB
 * insert fails here, files already written by THIS call are deleted before
 * rethrowing. The caller deletes them if its own transaction rolls back
 * later (see AddReply / CreateTicket).
 */
final class StoreAttachments
{
    /**
     * @param  list<UploadedFile>  $files
     * @return Collection<int, Attachment>
     */
    public function __invoke(Ticket $ticket, ?TicketReply $reply, User $uploader, array $files): Collection
    {
        $diskName = (string) config('support.attachments.disk');
        $disk = Storage::disk($diskName);
        $written = [];
        $attachments = collect();

        try {
            foreach ($files as $file) {
                $extension = strtolower($file->getClientOriginalExtension());
                $directory = sprintf('tickets/%d/%s', $ticket->getKey(), now()->format('Y/m'));
                $storedName = Str::uuid()->toString().'.'.$extension;

                $path = $disk->putFileAs($directory, $file, $storedName);

                if ($path === false) {
                    throw new RuntimeException("Could not store attachment on disk [{$diskName}].");
                }

                $written[] = $path;

                $attachments->push(Attachment::query()->create([
                    'ticket_id' => $ticket->getKey(),
                    'ticket_reply_id' => $reply?->getKey(),
                    'uploaded_by' => $uploader->getKey(),
                    'original_name' => SafeFilename::clean($file->getClientOriginalName()),
                    'stored_name' => $path,
                    'mime_type' => (string) $file->getMimeType(), // from content, not the client
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

    /** Remove the files behind attachments whose transaction did not commit. */
    public static function discard(Collection $attachments): void
    {
        $attachments->groupBy('disk')->each(
            fn (Collection $group, string $disk) => Storage::disk($disk)->delete($group->pluck('stored_name')->all())
        );
    }
}
