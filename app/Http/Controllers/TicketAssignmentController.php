<?php

namespace App\Http\Controllers;

use App\Actions\Assignment\AcceptTicket;
use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\ReleaseTicket;
use App\Exceptions\AssignmentConflict;
use App\Http\Requests\AssignTicketRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Support\DeferredPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unassigned queue, self-accept, admin (re)assignment and release. Lost
 * races surface as AssignmentConflict (409), never a 500 or an overwrite.
 */
class TicketAssignmentController extends Controller
{
    public function queue(Request $request): View
    {
        $this->authorize('view-unassigned-queue');

        // Deferred join (see DeferredPagination): ids from the index, rows for the page only.
        $tickets = DeferredPagination::paginate(
            Ticket::query()->active()->unassigned()->orderBy('due_at')->orderBy('id'),
            20,
            fn ($rows) => $rows->selectListColumns()->withListRelations(),
        );

        return view('tickets.unassigned', ['tickets' => $tickets]);
    }

    public function accept(Request $request, Ticket $ticket, AcceptTicket $acceptTicket): RedirectResponse|JsonResponse
    {
        $this->authorize('accept', $ticket);

        if (! $acceptTicket($request->user(), $ticket)) {
            throw AssignmentConflict::ticketTaken($ticket->ticket_number);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Ticket accepted.'), 'ticket_number' => $ticket->ticket_number]);
        }

        return redirect()->route('tickets.show', $ticket)->with('status', __('You accepted :number.', ['number' => $ticket->ticket_number]));
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assignTicket): RedirectResponse
    {
        $this->authorize('assign', $ticket);

        $agent = User::query()->findOrFail($request->validated('agent_id'));

        $assignTicket($request->user(), $ticket, $agent, $request->expectedAgentId());

        return redirect()->route('tickets.show', $ticket)->with('status', __('Assigned to :name.', ['name' => $agent->name]));
    }

    public function release(Request $request, Ticket $ticket, ReleaseTicket $releaseTicket): RedirectResponse
    {
        $this->authorize('release', $ticket);

        $releaseTicket($request->user(), $ticket);

        return redirect()->route('tickets.show', $ticket)->with('status', __('Ticket returned to the unassigned queue.'));
    }
}
