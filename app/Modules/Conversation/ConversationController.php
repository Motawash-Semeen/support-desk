<?php

namespace App\Modules\Conversation;

use App\Http\Controllers\Controller;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Requests\StoreReplyRequest;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Posting replies and internal notes, and downloading attachments. */
class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversation) {}

    /** StoreReplyRequest has already checked `reply` (or `work` for a note). */
    public function reply(StoreReplyRequest $request, Ticket $ticket): RedirectResponse
    {
        // Decided on the server: customers can only ever post public replies.
        $type = $request->replyType();

        $reply = $this->conversation->addReply(
            $request->user(),
            $ticket,
            $request->validated('body'),
            $type,
            $request->file('attachments', []),
        );

        return redirect()
            ->to(route('tickets.show', $ticket).'#reply-'.$reply->id)
            ->with('status', $type === ReplyType::InternalNote ? __('Internal note added.') : __('Reply sent.'));
    }

    /** Streams a file from the private disk after the policy check; unknown or forbidden files are 404. */
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
