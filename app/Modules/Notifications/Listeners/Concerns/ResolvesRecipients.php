<?php

namespace App\Modules\Notifications\Listeners\Concerns;

use App\Enums\Role;
use App\Models\User;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * Small helpers shared by the notification listeners. The one rule they all
 * apply: never notify the person who performed the action, and never the
 * same person twice for one event.
 */
trait ResolvesRecipients
{
    /** @return Collection<int, User> */
    protected function admins(): Collection
    {
        return User::query()->role(Role::Admin)->get();
    }

    protected function assigneeOf(Ticket $ticket): ?User
    {
        return $ticket->assigned_agent_id === null ? null : User::query()->find($ticket->assigned_agent_id);
    }

    /** The creator when that is a customer; staff-created tickets have no customer to inform. */
    protected function customerOf(Ticket $ticket): ?User
    {
        $creator = User::query()->find($ticket->created_by);

        return $creator?->isCustomer() ? $creator : null;
    }

    /**
     * @param  iterable<User|Collection<int, User>|null>  $users
     * @return Collection<int, User>
     */
    protected function recipients(iterable $users, ?User $actor): Collection
    {
        return collect($users)
            ->flatten()
            ->filter()
            ->unique(fn (User $user) => $user->getKey())
            ->reject(fn (User $user) => $actor !== null && $user->is($actor))
            ->values();
    }
}
