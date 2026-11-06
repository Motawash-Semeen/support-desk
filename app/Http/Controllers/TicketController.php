<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketController extends Controller
{
    /** Columns needed to render an attachment link. */
    private const ATTACHMENT_COLUMNS = ['id', 'ticket_id', 'ticket_reply_id', 'original_name', 'mime_type', 'size_bytes'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        $tickets = Ticket::query()
            ->visibleTo($request->user())
            ->selectListColumns()
            ->withListRelations()
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('tickets.index', ['tickets' => $tickets]);
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $canSeeInternal = $request->user()->can('viewInternalNotes', $ticket);

        $ticket->load([
            'creator:id,name',
            'assignee:id,name',
            'category:id,name',
        ]);

        // viewInternalNotes is the single switch between the full timeline and
        // the customer-visible one.
        $replies = ($canSeeInternal ? $ticket->replies() : $ticket->publicReplies())
            ->with([
                'author:id,name,role',
                'attachments' => fn ($query) => $query->select(self::ATTACHMENT_COLUMNS),
            ])
            ->oldest()
            ->oldest('id')
            ->get();

        $attachments = $ticket->attachments()
            ->whereNull('ticket_reply_id')
            ->get(self::ATTACHMENT_COLUMNS);

        $activities = $canSeeInternal
            ? $ticket->activities()->with('actor:id,name')->latest()->latest('id')->limit(50)->get()
            : collect();

        return view('tickets.show', [
            'ticket' => $ticket,
            'replies' => $replies,
            'attachments' => $attachments,
            'activities' => $activities,
            'canSeeInternal' => $canSeeInternal,
        ]);
    }
}
