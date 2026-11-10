<?php

namespace App\Modules\Tickets;

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;

/** Who may do what to one ticket. Role-only pages use the role: middleware instead. */
class TicketPolicy
{
    /**
     * A ticket the user may not see is a 404 for every ability, like one that does not
     * exist. Except an agent's accept: losing the race must answer 409.
     */
    public function before(User $user, string $ability, mixed ...$arguments): ?Response
    {
        $ticket = $arguments[0] ?? null;
        $agentAccepting = $ability === 'accept' && $user->isAgent();

        if ($ticket instanceof Ticket && ! $agentAccepting && ! $this->canSee($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return null;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->canSee($user, $ticket);
    }

    /** Work on the ticket (priority, category, status, internal notes): the assignee or an admin. */
    public function work(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() || $this->isAssignee($user, $ticket);
    }

    /** Edit subject and description; a customer only while the ticket is still open. */
    public function update(User $user, Ticket $ticket): bool
    {
        return $this->work($user, $ticket)
            || ($this->owns($user, $ticket) && $ticket->status === TicketStatus::Open);
    }

    /** Public replies. Closed tickets take none (the customer reopens first). */
    public function reply(User $user, Ticket $ticket): bool
    {
        return $ticket->status !== TicketStatus::Closed
            && ($this->work($user, $ticket) || $this->owns($user, $ticket));
    }

    /** Staff close at any point; the customer confirms a resolved ticket. */
    public function close(User $user, Ticket $ticket): bool
    {
        return $this->work($user, $ticket)
            || ($this->owns($user, $ticket) && $ticket->status === TicketStatus::Resolved);
    }

    /** Resolved: the owner, the assignee or an admin. Closed: an admin only. */
    public function reopen(User $user, Ticket $ticket): bool
    {
        return match ($ticket->status) {
            TicketStatus::Resolved => $this->work($user, $ticket) || ($user->isCustomer() && $this->owns($user, $ticket)),
            TicketStatus::Closed => $user->isAdmin(),
            default => false,
        };
    }

    /** Manual assignment to a chosen agent. */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() && $ticket->isActive();
    }

    /** Any agent may try; AssignmentService::claim() decides who wins. */
    public function accept(User $user, Ticket $ticket): bool
    {
        return $user->isAgent() && $ticket->isActive();
    }

    /** Hand an assigned ticket back to the unassigned queue. */
    public function release(User $user, Ticket $ticket): bool
    {
        return $this->work($user, $ticket)
            && $ticket->assigned_agent_id !== null
            && $ticket->isActive();
    }

    /** The only switch for internal-note visibility (replies, files, timeline). */
    public function viewInternalNotes(User $user, Ticket $ticket): bool
    {
        return $user->isStaff();
    }

    /** Who may see a ticket; must match Ticket::scopeVisibleTo(). */
    private function canSee(User $user, Ticket $ticket): bool
    {
        return match (true) {
            $user->isAdmin() => true,
            $user->isAgent() => $this->isAssignee($user, $ticket)
                || ($ticket->assigned_agent_id === null && $ticket->isActive()),
            default => $this->owns($user, $ticket),
        };
    }

    private function owns(User $user, Ticket $ticket): bool
    {
        return $ticket->created_by === $user->getKey();
    }

    private function isAssignee(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_agent_id !== null
            && $ticket->assigned_agent_id === $user->getKey();
    }
}
