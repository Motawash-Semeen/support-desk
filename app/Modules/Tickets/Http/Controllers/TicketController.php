<?php

namespace App\Modules\Tickets\Http\Controllers;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tickets\Actions\ChangeTicketStatus;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Actions\UpdateTicket;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Filters\TicketFilter;
use App\Modules\Tickets\Http\Requests\ChangeTicketStatusRequest;
use App\Modules\Tickets\Http\Requests\StoreTicketRequest;
use App\Modules\Tickets\Http\Requests\TicketIndexRequest;
use App\Modules\Tickets\Http\Requests\UpdateTicketRequest;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Support\DeferredPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin HTTP adapter: check permission, validate (FormRequest), call an Action,
 * respond. Every signed-in user may list and create tickets; the list is
 * limited to what the user may see (TicketFilter / visibleTo), and every
 * single-ticket action asks TicketPolicy.
 */
class TicketController extends Controller
{
    /** Columns needed to render an attachment link. */
    private const ATTACHMENT_COLUMNS = ['id', 'ticket_id', 'ticket_reply_id', 'original_name', 'mime_type', 'size_bytes'];

    public function index(TicketIndexRequest $request): View
    {
        $user = $request->user();
        $filter = new TicketFilter($user, $request->validated());

        // Deferred join: COUNT + page of ids from the covering indexes, then list
        // columns and three eager loads for the 15 rows shown (docs/DEVELOPER_GUIDE.md).
        $tickets = DeferredPagination::paginate(
            $filter->apply(Ticket::query()),
            15,
            fn ($rows) => $rows->selectListColumns()->withListRelations(),
        )->withQueryString();

        [$sort, $direction] = $filter->sortValue();

        return view('Tickets::index', [
            'tickets' => $tickets,
            'scope' => $filter->scopeValue(),
            'sort' => $sort,
            'direction' => $direction,
            'filters' => $request->validated(),
            'categories' => Category::query()->active()->get(['id', 'name']),
            // Staff-only filter options; customers never get them.
            'agents' => $user->isStaff() ? User::query()->agents()->orderBy('name')->get(['id', 'name']) : collect(),
            'selectedCustomer' => $user->isStaff() && $request->filled('customer')
                ? User::query()->role(Role::Customer)->find($request->integer('customer'), ['id', 'name', 'email'])
                : null,
        ]);
    }

    public function create(): View
    {
        return view('Tickets::create', [
            'categories' => Category::query()->active()->get(['id', 'name']),
            'priorities' => TicketPriority::cases(),
        ]);
    }

    public function store(StoreTicketRequest $request, CreateTicket $createTicket): RedirectResponse
    {
        $ticket = $createTicket(
            $request->user(),
            $request->safe()->except('attachments'),
            $request->file('attachments', []),
        );

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', __('Ticket :number created.', ['number' => $ticket->ticket_number]));
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $user = $request->user();

        $ticket->load(['creator:id,name', 'assignee:id,name', 'category:id,name']);

        // repliesVisibleTo / activitiesVisibleTo are the single switch between
        // the full conversation and the customer-visible one.
        $replies = $ticket->repliesVisibleTo($user)
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

        // Newest first by default; ?timeline=oldest flips it. simplePaginate: no
        // COUNT(*), served by ticket_activities(ticket_id, created_at).
        $direction = $request->query('timeline') === 'oldest' ? 'asc' : 'desc';

        $activities = $ticket->activitiesVisibleTo($user)
            ->with('actor:id,name')
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->simplePaginate(20, ['*'], 'timeline_page')
            ->withQueryString();

        return view('Tickets::show', [
            'ticket' => $ticket,
            'replies' => $replies,
            'attachments' => $attachments,
            'activities' => $activities,
            'timelineDirection' => $direction,
            'canSeeInternal' => $user->can('viewInternalNotes', $ticket),
            // Only admins get the (re)assign picker: available agents, id + name.
            'agents' => $user->can('assign', $ticket)
                ? User::query()->availableAgents()->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    public function edit(Request $request, Ticket $ticket): View
    {
        $this->authorize('update', $ticket);

        return view('Tickets::edit', [
            'ticket' => $ticket,
            'categories' => Category::query()->active()->get(['id', 'name']),
            'priorities' => TicketPriority::cases(),
            // Priority and category are for the assigned agent or an admin.
            'canWork' => $request->user()->can('work', $ticket),
        ]);
    }

    /** UpdateTicketRequest has already checked `update` (and `work` for priority/category). */
    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $updateTicket): RedirectResponse
    {
        $updateTicket($request->user(), $ticket, $request->validated());

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket updated.'));
    }

    /**
     * Every status change: resolve, close, reopen and the working statuses.
     * ChangeTicketStatusRequest has already checked the ability for the target.
     */
    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): RedirectResponse
    {
        $from = $ticket->status;
        $to = $request->target();

        $changeStatus($request->user(), $ticket, $to, $request->validated('reason'));

        $message = match (true) {
            $to === TicketStatus::Resolved => __('Ticket resolved.'),
            $to === TicketStatus::Closed => __('Ticket closed.'),
            ! $from->isActive() && $to->isActive() => __('Ticket reopened.'),
            default => __('Status changed to :status.', ['status' => $to->label()]),
        };

        return redirect()->route('tickets.show', $ticket)->with('status', $message);
    }
}
