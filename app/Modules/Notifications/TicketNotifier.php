<?php

namespace App\Modules\Notifications;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Models\Ticket;
use App\Support\BusinessTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Who hears about what. Services call one method per event; this class picks the
 * recipients and the wording. Never the person who acted (except the customer's
 * receipt), never twice, and only after the commit.
 *
 *   ticket created   customer: receipt; staff: admins + agents of the category
 *   reply            note -> the assignee (never the customer); staff reply -> the customer;
 *                    customer reply -> the assignee, or every admin while unassigned
 *   assigned         the agent (unless they accepted it) and the customer
 *   resolved         the customer (unless they resolved it)
 *   SLA warning      the assignee, or every admin while unassigned
 *   SLA breached     the assignee and every admin
 *   escalated (2+)   every admin
 */
class TicketNotifier
{
    public function ticketCreated(Ticket $ticket, ?User $actor): void
    {
        DB::afterCommit(function () use ($ticket, $actor): void {
            if ($customer = $this->customerOf($ticket)) {
                $this->send([$customer], null, $ticket, 'ticket_received', 'We received your ticket',
                    "Your ticket {$ticket->ticket_number} has been received. We will get back to you soon.");
            }

            $staff = User::query()
                ->where(fn ($query) => $query
                    ->where('role', Role::Admin)
                    ->orWhere(fn ($agents) => $agents
                        ->where('role', Role::Agent)
                        ->whereHas('categories', fn ($categories) => $categories->whereKey($ticket->category_id))))
                ->get();

            $this->send([$staff], $actor, $ticket, 'ticket_created', 'New ticket: '.$ticket->subject,
                "New {$ticket->priority->label()} priority ticket {$ticket->ticket_number}: {$ticket->subject}");
        });
    }

    public function replyAdded(TicketReply $reply, Ticket $ticket, User $author): void
    {
        DB::afterCommit(function () use ($reply, $ticket, $author): void {
            $excerpt = [Str::limit($reply->body, 500)];

            if ($reply->isInternal()) {
                $this->send([$this->assigneeOf($ticket)], $author, $ticket, 'internal_note_added', 'Internal note added',
                    "{$author->name} added an internal note on {$ticket->ticket_number}.", $excerpt);

                return; // internal notes never reach the customer
            }

            if ($author->isStaff()) {
                $this->send([$this->customerOf($ticket)], $author, $ticket, 'agent_replied', 'New reply on your ticket',
                    "{$author->name} replied to your ticket {$ticket->ticket_number}.", $excerpt);

                return;
            }

            $this->send([$this->assigneeOf($ticket) ?? $this->admins()], $author, $ticket, 'customer_replied', 'Customer replied',
                "The customer replied on {$ticket->ticket_number}.", $excerpt);
        });
    }

    /** $actor is null when the system assigned the ticket. */
    public function assigned(Ticket $ticket, User $agent, ?User $actor): void
    {
        DB::afterCommit(function () use ($ticket, $agent, $actor): void {
            $this->send([$agent], $actor, $ticket, 'assigned_to_you', 'Ticket assigned to you',
                "{$ticket->ticket_number} ({$ticket->subject}) is now assigned to you.");

            $this->send([$this->customerOf($ticket)], $actor, $ticket, 'ticket_assigned', 'Your ticket has an agent',
                "{$agent->name} is now handling your ticket {$ticket->ticket_number}.");
        });
    }

    public function resolved(Ticket $ticket, ?User $actor): void
    {
        DB::afterCommit(fn () => $this->send([$this->customerOf($ticket)], $actor, $ticket, 'ticket_resolved', 'Your ticket was resolved',
            "Your ticket {$ticket->ticket_number} was marked resolved. Reply or reopen it if the problem is not fixed."));
    }

    public function slaWarning(Ticket $ticket): void
    {
        DB::afterCommit(fn () => $this->send([$this->assigneeOf($ticket) ?? $this->admins()], null, $ticket, 'sla_warning', 'SLA deadline approaching',
            "{$ticket->ticket_number} is due ".BusinessTime::format($ticket->due_at).' ('.BusinessTime::zone().').'));
    }

    public function slaBreached(Ticket $ticket): void
    {
        DB::afterCommit(fn () => $this->send([$this->assigneeOf($ticket), $this->admins()], null, $ticket, 'sla_breached', 'SLA breached',
            "{$ticket->ticket_number} missed its deadline of ".BusinessTime::format($ticket->due_at).' ('.BusinessTime::zone().').'));
    }

    public function escalated(Ticket $ticket, int $level): void
    {
        DB::afterCommit(fn () => $this->send([$this->admins()], null, $ticket, 'ticket_escalated', "Escalated to level {$level}",
            "{$ticket->ticket_number} is still open long after its deadline (".BusinessTime::format($ticket->due_at).") and was escalated to level {$level}."));
    }

    /** Drops empties, duplicates and the actor, then sends. */
    private function send(iterable $users, ?User $actor, Ticket $ticket, string $kind, string $subject, string $message, array $details = []): void
    {
        $recipients = collect($users)
            ->flatten()
            ->filter()
            ->unique(fn (User $user) => $user->getKey())
            ->reject(fn (User $user) => $actor !== null && $user->is($actor))
            ->values();

        Notification::send($recipients, new TicketNotification($ticket, $kind, $subject, $message, $details));
    }

    private function admins(): Collection
    {
        return User::query()->role(Role::Admin)->get();
    }

    private function assigneeOf(Ticket $ticket): ?User
    {
        return $ticket->assigned_agent_id === null ? null : User::query()->find($ticket->assigned_agent_id);
    }

    /** The creator, if they are a customer. */
    private function customerOf(Ticket $ticket): ?User
    {
        $creator = User::query()->find($ticket->created_by);

        return $creator?->isCustomer() ? $creator : null;
    }
}
