<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read an attachment: streamed from the private disk after
 * AttachmentPolicy (ticket visibility + internal-note rule). Denials and
 * unknown ids are both 404, and the storage path never leaves the server.
 */
class AttachmentController extends Controller
{
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->authorize('download', $attachment);

        $disk = Storage::disk($attachment->disk);
        $path = $attachment->path();

        if (! $disk->exists($path)) {
            Log::warning('Attachment file missing on disk.', [
                'attachment_id' => $attachment->id,
                'ticket_id' => $attachment->ticket_id,
                'disk' => $attachment->disk,
            ]);

            abort(404, __('This file is no longer available.'));
        }

        $headers = [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            // Even an inline image cannot run script or load anything.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ];

        // Inline only for PNG/JPEG on request; every other type is a download.
        $disposition = $request->boolean('inline') && $attachment->canDisplayInline() ? 'inline' : 'attachment';

        return $disk->response($path, $attachment->downloadName(), $headers, $disposition);
    }
}
