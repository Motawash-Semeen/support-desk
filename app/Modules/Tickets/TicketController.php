<?php

namespace App\Modules\Tickets;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Requests\ChangeTicketStatusRequest;
use App\Modules\Tickets\Requests\StoreTicketRequest;
use App\Modules\Tickets\Requests\TicketIndexRequest;
use App\Modules\Tickets\Requests\UpdateTicketRequest;
use App\Support\DeferredPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ticket pages: check permission, validate, call TicketService, respond. */
class TicketController extends Controller
{
    /** Columns needed to render an attachment link. */
    private const ATTACHMENT_COLUMNS = ['id', 'ticket_id', 'ticket_reply_id', 'original_name', 'mime_type', 'size_bytes'];

    public function __construct(private readonly TicketService $tickets) {}

    public function index(TicketIndexRequest $request): View
    {
        $user = $request->user();
        $filter = new TicketFilter($user, $request->validated());

        // Ids first, then the 15 rows with their relations.
        $tickets = DeferredPagination::paginate(
            $filter->query(),
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

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $ticket = $this->tickets->create(
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

        // Staff see everything; customers only public replies and entries.
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

        // Newest first; ?timeline=oldest reverses it.
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
    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->tickets->update($request->user(), $ticket, $request->validated());

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket updated.'));
    }

    /** Every status change; the request has already checked the ability for the target. */
    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket): RedirectResponse
    {
        $from = $ticket->status;
        $to = $request->target();

        $this->tickets->changeStatus($request->user(), $ticket, $to, $request->validated('reason'));

        $message = match (true) {
            $to === TicketStatus::Resolved => __('Ticket resolved.'),
            $to === TicketStatus::Closed => __('Ticket closed.'),
            ! $from->isActive() && $to->isActive() => __('Ticket reopened.'),
            default => __('Status changed to :status.', ['status' => $to->label()]),
        };

        return redirect()->route('tickets.show', $ticket)->with('status', $message);
    }

    /** Customer autocomplete for the staff filter: prefix match on name or email, at most 10. */
    public function customerSearch(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];
        $prefix = TicketFilter::escapeLike(trim($q)).'%';

        $customers = User::query()
            ->where('role', Role::Customer)
            ->where(fn ($query) => $query->where('name', 'like', $prefix)->orWhere('email', 'like', $prefix))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email']);

        return response()->json($customers->map->only(['id', 'name', 'email'])->values());
    }
}
