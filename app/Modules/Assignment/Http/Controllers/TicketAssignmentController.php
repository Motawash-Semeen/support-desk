<?php

namespace App\Modules\Assignment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assignment\Actions\AcceptTicket;
use App\Modules\Assignment\Actions\AssignTicket;
use App\Modules\Assignment\Actions\ReleaseTicket;
use App\Modules\Assignment\Exceptions\AssignmentConflict;
use App\Modules\Assignment\Http\Requests\AssignTicketRequest;
use App\Modules\Tickets\Models\Ticket;
use App\Support\DeferredPagination;
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
        // Deferred join (see DeferredPagination): ids from the index, rows for the page only.
        $tickets = DeferredPagination::paginate(
            Ticket::query()->active()->unassigned()->orderBy('due_at')->orderBy('id'),
            20,
            fn ($rows) => $rows->selectListColumns()->withListRelations(),
        );

        return view('Assignment::unassigned', ['tickets' => $tickets]);
    }

    public function accept(Request $request, Ticket $ticket, AcceptTicket $acceptTicket): RedirectResponse
    {
        $this->authorize('accept', $ticket);

        if (! $acceptTicket($request->user(), $ticket)) {
            throw AssignmentConflict::ticketTaken($ticket->ticket_number);
        }

        return redirect()->route('tickets.show', $ticket)->with('status', __('You accepted :number.', ['number' => $ticket->ticket_number]));
    }

    /** AssignTicketRequest has already checked `assign`. */
    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $assignTicket): RedirectResponse
    {
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
