<?php

namespace App\Modules\Tickets\Policies;

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;

/**
 * Per-ticket authorization. Roles come from users.role; ownership means
 * tickets.created_by (customer) or tickets.assigned_agent_id (agent).
 *
 * Policies answer "may this user do this to this ticket in its current
 * state". Whether a status change is a legal transition is validated again
 * inside the Action with TicketStatus::canTransitionTo().
 *
 * Lists are protected separately by Ticket::scopeVisibleTo().
 */
class TicketPolicy
{
    /**
     * A customer touching another customer's ticket gets 404 for EVERY ability,
     * so responses never confirm that a ticket number exists.
     */
    public function before(User $user, string $ability, mixed ...$arguments): ?Response
    {
        $ticket = $arguments[0] ?? null;

        if ($ticket instanceof Ticket && $user->isCustomer() && ! $this->owns($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return null;
    }

    /** Anyone signed in may open the list; the query is scoped with visibleTo(). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() || $this->owns($user, $ticket);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Edit subject/description (and, via changePriority/changeCategory, the
     * other fields). Customers may edit only while the ticket is still open;
     * the FormRequest limits them to subject and description.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin()
            || $this->isAssignee($user, $ticket)
            || ($this->owns($user, $ticket) && $ticket->status === TicketStatus::Open);
    }

    public function changePriority(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() || $this->isAssignee($user, $ticket);
    }

    public function changeCategory(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() || $this->isAssignee($user, $ticket);
    }

    /** Manual assignment to a chosen agent. */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() && $ticket->isActive();
    }

    /**
     * Who may TRY to self-claim: any agent, on an active ticket. Whether the
     * ticket is still unassigned is decided atomically by ClaimTicket, so a
     * lost race is a 409 Conflict ("just taken"), not a 403. Views add the
     * unassigned check to hide the button (D-070).
     */
    public function accept(User $user, Ticket $ticket): bool
    {
        return $user->isAgent() && $ticket->isActive();
    }

    /** Return an assigned ticket to the unassigned queue. */
    public function release(User $user, Ticket $ticket): bool
    {
        return ($user->isAdmin() || $this->isAssignee($user, $ticket))
            && $ticket->assigned_agent_id !== null
            && $ticket->isActive();
    }

    /**
     * Public replies. Closed tickets take none: the customer reopens first
     * (D-079). Internal notes stay possible on closed tickets.
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        if ($ticket->status === TicketStatus::Closed) {
            return false;
        }

        return $user->isAdmin()
            || $this->isAssignee($user, $ticket)
            || $this->owns($user, $ticket);
    }

    /** Decision D-036: only the assigned agent or an admin may add notes. */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() || $this->isAssignee($user, $ticket);
    }

    public function resolve(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() || $this->isAssignee($user, $ticket);
    }

    /** Staff close at any point; the customer confirms a resolved ticket. */
    public function close(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin()
            || $this->isAssignee($user, $ticket)
            || ($this->owns($user, $ticket) && $ticket->status === TicketStatus::Resolved);
    }

    /**
     * Resolved tickets may be reopened by the customer owner, the assignee or
     * an admin. Closed is terminal: only an admin may reopen it.
     */
    public function reopen(User $user, Ticket $ticket): bool
    {
        return match ($ticket->status) {
            TicketStatus::Resolved => $user->isAdmin()
                || $this->isAssignee($user, $ticket)
                || ($user->isCustomer() && $this->owns($user, $ticket)),
            TicketStatus::Closed => $user->isAdmin(),
            default => false,
        };
    }

    /**
     * Move between working statuses (open / in progress / waiting for
     * customer). Resolve, close and reopen have their own abilities.
     */
    public function changeStatus(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin() || $this->isAssignee($user, $ticket);
    }

    /** The ONLY gate for internal-note visibility (replies, attachments, timeline). */
    public function viewInternalNotes(User $user, Ticket $ticket): bool
    {
        return $user->isStaff();
    }

    /** Bulk CSV import. Class-level: Gate::allows('import', Ticket::class). */
    public function import(User $user): bool
    {
        return $user->isAdmin();
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
