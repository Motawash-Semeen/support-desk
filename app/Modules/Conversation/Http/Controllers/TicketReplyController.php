<?php

namespace App\Modules\Conversation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Conversation\Actions\AddReply;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Http\Requests\StoreReplyRequest;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\RedirectResponse;

class TicketReplyController extends Controller
{
    public function store(StoreReplyRequest $request, Ticket $ticket, AddReply $addReply): RedirectResponse
    {
        // Derived on the server: customers can only ever post public replies.
        $type = $request->replyType();

        $this->authorize($type === ReplyType::InternalNote ? 'addInternalNote' : 'reply', $ticket);

        $reply = $addReply(
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
}
