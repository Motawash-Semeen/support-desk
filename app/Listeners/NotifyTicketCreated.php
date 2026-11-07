<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Events\TicketCreated;
use App\Listeners\Concerns\ResolvesRecipients;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Customer: a receipt (the one deliberate exception to "never notify the
 * actor": it confirms the ticket was received). Staff: admins and agents
 * specialised in the ticket's category.
 */
class NotifyTicketCreated implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use ResolvesRecipients;

    public function handle(TicketCreated $event): void
    {
        $ticket = $event->ticket;

        if ($customer = $this->customerOf($ticket)) {
            Notification::send($customer, new TicketCreatedNotification($ticket, forCustomer: true));
        }

        $staff = User::query()
            ->where(fn ($query) => $query
                ->where('role', Role::Admin)
                ->orWhere(fn ($agents) => $agents
                    ->where('role', Role::Agent)
                    ->whereHas('categories', fn ($categories) => $categories->whereKey($ticket->category_id))))
            ->get();

        Notification::send($this->recipients([$staff], $event->actor), new TicketCreatedNotification($ticket, forCustomer: false));
    }
}
