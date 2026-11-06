<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Streams a file from the private disk. The route uses scoped bindings,
     * so an attachment id that does not belong to {ticket} is a 404 before
     * this runs.
     */
    public function download(Ticket $ticket, Attachment $attachment): StreamedResponse
    {
        $attachment->setRelation('ticket', $ticket);

        $this->authorize('download', $attachment);

        $disk = Storage::disk($attachment->disk);
        $path = $attachment->path();

        if (! $disk->exists($path)) {
            Log::warning('Attachment file missing on disk.', [
                'attachment_id' => $attachment->id,
                'ticket_id' => $ticket->id,
                'disk' => $attachment->disk,
                'path' => $path,
            ]);

            abort(404, __('This file is no longer available.'));
        }

        return $disk->download($path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
