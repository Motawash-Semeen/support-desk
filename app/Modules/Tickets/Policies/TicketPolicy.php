<?php

namespace App\Modules\Tickets\Policies;

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to one ticket. Roles come from users.role; ownership means
 * tickets.created_by (the customer) or tickets.assigned_agent_id (the agent).
 *
 * Pages that depend only on the role (dashboard, queue, admin screens) are
 * guarded by the `role:` route middleware instead, so they need no ability here.
 * Whether a status move is legal is checked again by ChangeTicketStatus.
 */
class TicketPolicy
{
    /**
     * A ticket the user may not see is a 404 for every ability, exactly like a
     * ticket number that does not exist. Accept is the exception for agents: an
     * agent who loses the race for a queue ticket gets ClaimTicket's 409
     * "just taken" instead.
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

    /**
     * Work on the ticket: change priority, category or working status, resolve
     * it, and write internal notes. The assigned agent or an admin.
     */
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

    /**
     * Any agent may TRY to take an active ticket; ClaimTicket decides
     * atomically whether it is still unassigned (a lost race is a 409).
     */
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

    /**
     * Same rule as Ticket::scopeVisibleTo(): admins see everything; agents see
     * their own tickets and active unassigned ones (the queue); customers see
     * the tickets they created.
     */
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
