<?php

namespace App\Modules\Sla\Actions;

use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Services\TicketActivityRecorder;
use App\Modules\Assignment\Jobs\AutoAssignTicket;
use App\Modules\Sla\Enums\EscalationKind;
use App\Modules\Sla\Events\SlaBreached;
use App\Modules\Sla\Events\SlaWarningReached;
use App\Modules\Sla\Events\TicketEscalated;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies one SLA escalation step to one ticket, at most once per deadline.
 *
 * The idempotency lock is a guarded UPDATE (compare-and-set), the same
 * principle as ticket assignment:
 *
 *   warning: SET warning_notified_at = now
 *            WHERE id = ? AND warning_notified_at IS NULL AND status IN (active) AND sla_warning_at <= now
 *   breach:  SET breached_notified_at = now, is_escalated = 1, escalation_level = max(level, 1)
 *            WHERE id = ? AND breached_notified_at IS NULL AND status IN (active) AND due_at < now
 *   level 2: SET escalation_level = 2
 *            WHERE id = ? AND escalation_level = 1 AND is_escalated = 1 AND status IN (active)
 *                  AND due_at <= now - level2_after_minutes
 *
 * Only when the UPDATE affected one row are activities written and events
 * dispatched. Duplicate jobs, overlapping scans and retries do nothing. The
 * WHERE also re-checks the ticket is still active and still due, so a ticket
 * resolved (or re-prioritised) after the scan picked it is never escalated.
 */
final class EscalateTicket
{
    public function __construct(private readonly TicketActivityRecorder $activity) {}

    /** @return bool true when this call applied the step */
    public function __invoke(int $ticketId, EscalationKind $kind): bool
    {
        return DB::transaction(fn (): bool => match ($kind) {
            EscalationKind::Warning => $this->warn($ticketId),
            EscalationKind::Breach => $this->breach($ticketId),
            EscalationKind::LevelTwo => $this->levelTwo($ticketId),
        });
    }

    private function warn(int $ticketId): bool
    {
        $affected = $this->guarded($ticketId)
            ->whereNull('warning_notified_at')
            ->where('sla_warning_at', '<=', now())
            ->update(['warning_notified_at' => now()]);

        if ($affected === 0) {
            return false;
        }

        $ticket = Ticket::query()->findOrFail($ticketId);

        $this->activity->record($ticket, ActivityType::SlaWarning, null, 'SLA warning: due '.BusinessTime::format($ticket->due_at), [], [
            'due_at' => $ticket->due_at?->toIso8601String(),
        ]);

        SlaWarningReached::dispatch($ticket);

        return true;
    }

    private function breach(int $ticketId): bool
    {
        $affected = $this->guarded($ticketId)
            ->whereNull('breached_notified_at')
            ->where('due_at', '<', now())
            ->update([
                'breached_notified_at' => now(),
                'is_escalated' => true,
                'escalation_level' => DB::raw('CASE WHEN escalation_level < 1 THEN 1 ELSE escalation_level END'),
            ]);

        if ($affected === 0) {
            return false;
        }

        $ticket = Ticket::query()->findOrFail($ticketId);
        $due = BusinessTime::format($ticket->due_at);

        $this->activity->record($ticket, ActivityType::SlaBreached, null, "SLA breached: was due {$due}", [], [
            'due_at' => $ticket->due_at?->toIso8601String(),
        ]);
        $this->activity->record($ticket, ActivityType::Escalated, null, 'Escalated to level 1 (SLA breached)', [], [
            'escalation_level' => $ticket->escalation_level,
        ]);

        SlaBreached::dispatch($ticket); // notifies the assignee and every admin

        if ($ticket->assigned_agent_id === null) {
            // Nobody owns an overdue ticket: try to assign it now rather than at the next 5-minute retry.
            AutoAssignTicket::dispatch($ticket->id)->afterCommit();
        }

        return true;
    }

    private function levelTwo(int $ticketId): bool
    {
        $minutes = (int) config('support.escalation.level2_after_minutes');

        $affected = $this->guarded($ticketId)
            ->where('is_escalated', true)
            ->where('escalation_level', 1)
            ->where('due_at', '<=', now()->subMinutes($minutes))
            ->update(['escalation_level' => 2]);

        if ($affected === 0) {
            return false;
        }

        $ticket = Ticket::query()->findOrFail($ticketId);

        $this->activity->record($ticket, ActivityType::Escalated, null, "Escalated to level 2 (still open {$minutes} minutes after the deadline)", [
            'escalation_level' => 1,
        ], [
            'escalation_level' => 2,
        ]);

        TicketEscalated::dispatch($ticket, 2);

        return true;
    }

    /** The part of every guard that is shared: this ticket, still active. */
    private function guarded(int $ticketId): Builder
    {
        return Ticket::query()
            ->whereKey($ticketId)
            ->whereIn('status', TicketStatus::activeValues());
    }
}
