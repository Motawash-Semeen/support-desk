<?php

namespace App\Modules\Assignment;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assignment\Requests\AssignTicketRequest;
use App\Modules\Tickets\Models\Ticket;
use App\Support\DeferredPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Unassigned queue, accept, assign, release and the agent's availability switch. */
class AssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignment) {}

    public function queue(): View
    {
        // Ids first, then the rows for this page only.
        $tickets = DeferredPagination::paginate(
            Ticket::query()->active()->unassigned()->orderBy('due_at')->orderBy('id'),
            20,
            fn ($rows) => $rows->selectListColumns()->withListRelations(),
        );

        return view('Assignment::unassigned', ['tickets' => $tickets]);
    }

    public function accept(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('accept', $ticket);

        $this->assignment->accept($request->user(), $ticket);

        return redirect()->route('tickets.show', $ticket)->with('status', __('You accepted :number.', ['number' => $ticket->ticket_number]));
    }

    /** AssignTicketRequest has already checked `assign`. */
    public function assign(AssignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $agent = User::query()->findOrFail($request->validated('agent_id'));

        $this->assignment->assign($request->user(), $ticket, $agent, $request->expectedAgentId());

        return redirect()->route('tickets.show', $ticket)->with('status', __('Assigned to :name.', ['name' => $agent->name]));
    }

    public function release(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('release', $ticket);

        $this->assignment->release($request->user(), $ticket);

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket returned to the unassigned queue.'));
    }

    /** Agents only (role:agent). */
    public function updateAvailability(Request $request): RedirectResponse
    {
        $available = (bool) $request->validate(['is_available' => ['required', 'boolean']])['is_available'];

        $this->assignment->setAvailability($request->user(), $available);

        return back()->with('status', $available
            ? __('You are available for new tickets.')
            : __('You will not receive auto-assigned tickets.'));
    }
}
