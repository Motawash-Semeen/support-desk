<?php

namespace App\Modules\Assignment\Actions\Concerns;

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Events\TicketStatusChanged;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Shared pieces of the compare-and-set assignment writes.
 */
trait GuardedAssignment
{
    /**
     * `status` becomes in_progress when it was open, otherwise unchanged,
     * evaluated by the database inside the same atomic UPDATE. Values come
     * from the enum, never from input.
     */
    private static function openBecomesInProgress(): Expression
    {
        return DB::raw(sprintf(
            "CASE WHEN status = '%s' THEN '%s' ELSE status END",
            TicketStatus::Open->value,
            TicketStatus::InProgress->value,
        ));
    }

    private static function touchAgent(User $agent, mixed $at): void
    {
        // Query-builder update: no model events, no extra read.
        User::query()->whereKey($agent->getKey())->update(['last_assigned_at' => $at]);
    }

    private static function announceStatusChange(Ticket $ticket, ?User $actor, ?string $before): void
    {
        $before = $before === null ? null : TicketStatus::from($before);

        if ($before !== null && $before !== $ticket->status) {
            TicketStatusChanged::dispatch($ticket, $actor, $before, $ticket->status);
        }
    }
}
