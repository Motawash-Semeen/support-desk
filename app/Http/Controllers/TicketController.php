<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CloseTicket;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\ReopenTicket;
use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Filters\TicketFilter;
use App\Http\Requests\ChangeTicketStatusRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\TicketIndexRequest;
use App\Http\Requests\TicketStatusActionRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\User;
use App\Support\DeferredPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thin HTTP adapter: authorize, validate (FormRequest), call an Action,
 * respond. {ticket} is resolved through visibleTo() (AppServiceProvider), so
 * customers get a 404 for tickets that are not theirs.
 */
class TicketController extends Controller
{
    /** Columns needed to render an attachment link. */
    private const ATTACHMENT_COLUMNS = ['id', 'ticket_id', 'ticket_reply_id', 'original_name', 'mime_type', 'size_bytes'];

    public function index(TicketIndexRequest $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        $user = $request->user();
        $filter = new TicketFilter($user, $request->validated());

        // visibleTo() first (inside apply). Deferred join: COUNT + page of ids from
        // the covering indexes, then list columns and three eager loads for the
        // 15 rows shown. A constant number of queries per page (docs/PERFORMANCE.md).
        $tickets = DeferredPagination::paginate(
            $filter->apply(Ticket::query()),
            15,
            fn ($rows) => $rows->selectListColumns()->withListRelations(),
        )->withQueryString();

        [$sort, $direction] = $filter->sortValue();

        return view('tickets.index', [
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
        $this->authorize('create', Ticket::class);

        return view('tickets.create', [
            'categories' => Category::query()->active()->get(['id', 'name']),
            'priorities' => TicketPriority::cases(),
        ]);
    }

    public function store(StoreTicketRequest $request, CreateTicket $createTicket): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $ticket = $createTicket(
            $request->user(),
            $request->safe()->except('attachments'),
            $request->file('attachments', []),
        );

        return redirect()
            ->route('tickets.show', $ticket)
            ->with('status', __('Ticket :number created.', ['number' => $ticket->ticket_number]));
    }

    public function show(Request $request, Ticket $ticket): View|TicketResource
    {
        $this->authorize('view', $ticket);

        $user = $request->user();
        $canSeeInternal = $user->can('viewInternalNotes', $ticket);

        $ticket->load([
            'creator:id,name',
            'assignee:id,name',
            'category:id,name',
        ]);

        // repliesVisibleTo / activitiesVisibleTo are the single switch between
        // the full conversation and the customer-visible one (HTML and JSON).
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

        // Newest first by default; ?timeline=oldest flips it. A whitelist mapping,
        // so the raw query value never reaches SQL. simplePaginate: no COUNT(*),
        // served by ticket_activities(ticket_id, created_at).
        $direction = $request->query('timeline') === 'oldest' ? 'asc' : 'desc';

        $activities = $ticket->activitiesVisibleTo($user)
            ->with('actor:id,name')
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->simplePaginate(20, ['*'], 'timeline_page')
            ->withQueryString();

        if ($request->expectsJson()) {
            return new TicketResource($ticket
                ->setRelation('visibleReplies', $replies)
                ->setRelation('visibleActivities', $activities->getCollection())
                ->setRelation('ticketAttachments', $attachments));
        }

        return view('tickets.show', [
            'ticket' => $ticket,
            'replies' => $replies,
            'attachments' => $attachments,
            'activities' => $activities,
            'timelineDirection' => $direction,
            'canSeeInternal' => $canSeeInternal,
            // Only admins get the (re)assign picker: available agents, id + name.
            'agents' => $request->user()->can('assign', $ticket)
                ? User::query()->availableAgents()->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    public function edit(Request $request, Ticket $ticket): View
    {
        $this->authorize('update', $ticket);

        return view('tickets.edit', [
            'ticket' => $ticket,
            'categories' => Category::query()->active()->get(['id', 'name']),
            'priorities' => TicketPriority::cases(),
            'canChangePriority' => $request->user()->can('changePriority', $ticket),
            'canChangeCategory' => $request->user()->can('changeCategory', $ticket),
        ]);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $updateTicket): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $updateTicket($request->user(), $ticket, $request->validated());

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket updated.'));
    }

    public function resolve(TicketStatusActionRequest $request, Ticket $ticket, ResolveTicket $resolveTicket): RedirectResponse
    {
        $this->authorize('resolve', $ticket);

        $resolveTicket($request->user(), $ticket, $request->validated('reason'));

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket resolved.'));
    }

    public function close(TicketStatusActionRequest $request, Ticket $ticket, CloseTicket $closeTicket): RedirectResponse
    {
        $this->authorize('close', $ticket);

        $closeTicket($request->user(), $ticket, $request->validated('reason'));

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket closed.'));
    }

    public function reopen(TicketStatusActionRequest $request, Ticket $ticket, ReopenTicket $reopenTicket): RedirectResponse
    {
        $this->authorize('reopen', $ticket);

        $reopenTicket($request->user(), $ticket, $request->validated('reason'));

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket reopened.'));
    }

    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): RedirectResponse
    {
        $to = $request->target();

        $this->authorize(ChangeTicketStatusRequest::abilityFor($ticket, $to), $ticket);

        $changeStatus($request->user(), $ticket, $to, $request->validated('reason'));

        return redirect()->route('tickets.show', $ticket)->with('status', __('Status changed to :status.', ['status' => $to->label()]));
    }
}
