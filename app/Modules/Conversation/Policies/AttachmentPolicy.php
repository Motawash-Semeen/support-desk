<?php

namespace App\Modules\Conversation\Policies;

use App\Models\User;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Tickets\Policies\TicketPolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * An attachment is visible exactly when its ticket is, except that files on
 * an internal note follow the internal-note rule (TicketPolicy::viewInternalNotes).
 * Denials are 404s so file ids cannot be probed.
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

        if ($attachment->reply?->isInternal() && Gate::forUser($user)->denies('viewInternalNotes', $attachment->ticket)) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function download(User $user, Attachment $attachment): Response
    {
        return $this->view($user, $attachment);
    }
}
