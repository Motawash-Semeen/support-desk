<?php

namespace App\Services\Assignment;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The pool of agents a ticket may go to, shared by every strategy:
 *
 *   role = agent AND is_available = 1
 *   AND (specialised in the ticket's category OR no categories at all = generalist)
 *
 * falling back to every available agent when that set is empty.
 */
final class CandidateAgents
{
    /** @return list<int> */
    public function idsFor(Ticket $ticket): array
    {
        $matched = User::query()
            ->availableAgents()
            ->where(fn (Builder $query) => $query
                ->whereHas('categories', fn (Builder $categories) => $categories->whereKey($ticket->category_id))
                ->orWhereDoesntHave('categories'))
            ->pluck('id')
            ->all();

        return $matched !== [] ? $matched : User::query()->availableAgents()->pluck('id')->all();
    }

    /**
     * SELECT ... FOR UPDATE on the candidates, always in id order so two
     * transactions locking overlapping pools cannot deadlock. Held until the
     * surrounding transaction commits; concurrent auto-assignments for the
     * same pool queue up here and then count each other's new tickets.
     * Re-checks availability under the lock.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function lock(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereKey($ids)
            ->availableAgents()
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all();
    }
}
