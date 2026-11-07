<?php

namespace App\Actions\Tickets;

use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Events\TicketReopened;
use App\Events\TicketResolved;
use App\Events\TicketStatusChanged;
use App\Exceptions\InvalidTicketTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\TicketSlaRecalculator;
use App\Services\TicketActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only place a ticket's status changes. Legality comes from
 * TicketStatus::canTransitionTo(); timestamps and SLA follow the move.
 */
final class ChangeTicketStatus
{
    public function __construct(
        private readonly TicketSlaRecalculator $recalculator,
        private readonly TicketActivityRecorder $activity,
    ) {}

    /**
     * $actor null = the system (e.g. tickets:auto-close-resolved); the system
     * gets no admin-only transitions.
     *
     * @throws InvalidTicketTransition
     */
    public function __invoke(?User $actor, Ticket $ticket, TicketStatus $to, ?string $reason = null): Ticket
    {
        return DB::transaction(function () use ($actor, $ticket, $to, $reason): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($to, (bool) $actor?->isAdmin())) {
                throw InvalidTicketTransition::between($from, $to, $locked->id);
            }

            $now = CarbonImmutable::now();
            $reopening = ! $from->isActive() && $to->isActive();

            $locked->status = $to;

            if ($to === TicketStatus::Resolved) {
                $locked->resolved_at = $now;
            }

            if ($to === TicketStatus::Closed) {
                $locked->closed_at = $now;
            }

            if ($reopening) {
                $locked->fill([
                    'resolved_at' => null,
                    'closed_at' => null,
                    'is_escalated' => false,
                    'escalation_level' => 0,
                ]);
            }

            $locked->save();

            $this->activity->record(
                $locked,
                $reopening ? ActivityType::Reopened : ActivityType::StatusChanged,
                $actor,
                $reopening
                    ? "Ticket reopened ({$from->label()} to {$to->label()})"
                    : "Status changed from {$from->label()} to {$to->label()}",
                ['status' => $from->value],
                array_filter(['status' => $to->value, 'reason' => $reason]),
            );

            // A reopened ticket gets a fresh target counted from the reopen time.
            if ($reopening && $this->recalculator->apply($locked, $now, $actor, 'ticket reopened')) {
                $locked->save();
            }

            TicketStatusChanged::dispatch($locked, $actor, $from, $to, $reason);

            if ($to === TicketStatus::Resolved) {
                TicketResolved::dispatch($locked, $actor);
            }

            if ($reopening) {
                TicketReopened::dispatch($locked, $actor, $from);
            }

            return $locked;
        });
    }
}
