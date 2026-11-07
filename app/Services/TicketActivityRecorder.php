<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;

/**
 * Appends entries to a ticket's history. Called explicitly by Actions inside
 * their transaction, so the history commits or rolls back with the change.
 * A null actor means the system acted; timelines render it as "System".
 */
class TicketActivityRecorder
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        Ticket $ticket,
        ActivityType $type,
        ?User $actor,
        string $description,
        array $old = [],
        array $new = [],
    ): TicketActivity {
        return $ticket->activities()->create([
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'description' => mb_strimwidth($description, 0, 255, '…'),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
        ]);
    }
}
