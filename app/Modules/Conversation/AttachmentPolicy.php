<?php

namespace App\Modules\Conversation;

use App\Models\User;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * An attachment is visible when its ticket is; files on internal notes also
 * need viewInternalNotes. Denials are 404s.
 */
class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): Response
    {
        $attachment->loadMissing(['ticket', 'reply:id,type']);

        $ticketAccess = Gate::forUser($user)->inspect('view', $attachment->ticket);

        if ($ticketAccess->denied()) {
            return Response::denyAsNotFound();
        }

        $reply = $attachment->reply;

        if ($reply instanceof TicketReply && $reply->isInternal() && Gate::forUser($user)->denies('viewInternalNotes', $attachment->ticket)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function download(User $user, Attachment $attachment): Response
    {
        return $this->view($user, $attachment);
    }
}
