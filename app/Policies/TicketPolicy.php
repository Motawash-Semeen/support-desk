<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
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

    /** An agent self-claims an unassigned, active ticket. */
    public function accept(User $user, Ticket $ticket): bool
    {
        return $user->isAgent()
            && $ticket->assigned_agent_id === null
            && $ticket->isActive();
    }

    /** Move an assigned ticket to a different agent. */
    public function reassign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin()
            && $ticket->assigned_agent_id !== null
            && $ticket->isActive();
    }

    /** Return an assigned ticket to the unassigned queue. */
    public function release(User $user, Ticket $ticket): bool
    {
        return ($user->isAdmin() || $this->isAssignee($user, $ticket))
            && $ticket->assigned_agent_id !== null
            && $ticket->isActive();
    }

    public function reply(User $user, Ticket $ticket): bool
    {
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

    /** Customers reopen a resolved ticket; staff reopen resolved or closed ones. */
    public function reopen(User $user, Ticket $ticket): bool
    {
        if ($this->owns($user, $ticket) && ! $user->isStaff()) {
            return $ticket->status === TicketStatus::Resolved;
        }

        return ($user->isAdmin() || $this->isAssignee($user, $ticket))
            && ! $ticket->isActive();
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
