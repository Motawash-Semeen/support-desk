<?php

namespace App\Modules\Sla\Console\Commands;

use App\Modules\Sla\Enums\EscalationKind;
use App\Modules\Sla\Jobs\EscalateTicketJob;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scheduled every minute. Stateless: recomputes the truth from the database
 * on every run and queues one small EscalateTicketJob per ticket.
 *
 *   warning pass: active, warning not yet notified, sla_warning_at <= now, due_at >= now
 *                 -> (status, sla_warning_at, due_at, warning_notified_at), index-only
 *   breach pass:  active, breach not yet notified, due_at < now
 *                 -> (status, due_at, breached_notified_at), index-only
 *   level-2 pass: active, escalated at level 1, due_at <= now - level2_after_minutes
 *                 -> (status, is_escalated, escalation_level, due_at), index-only
 *
 * Warning/breach use the same rules as Ticket::slaStatus() (SlaStatusResolver).
 * Each pass reads its candidate ids once (a snapshot), so jobs flipping the
 * flags while the scan dispatches cannot make it skip or repeat a ticket;
 * jobs are unique per ticket and kind, and EscalateTicket is guarded anyway.
 */
class ScanSla extends Command
{
    protected $signature = 'sla:scan {--chunk=200 : Jobs dispatched per batch}';

    protected $description = 'Queue SLA warning, breach and level-2 escalation jobs';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $level2Cutoff = now()->subMinutes((int) config('support.escalation.level2_after_minutes'));

        $passes = [
            [EscalationKind::Warning, Ticket::query()->slaApproaching()->whereNull('warning_notified_at')],
            [EscalationKind::Breach, Ticket::query()->slaBreached()->whereNull('breached_notified_at')],
            [EscalationKind::LevelTwo, Ticket::query()
                ->whereIn('status', TicketStatus::activeValues())
                ->where('is_escalated', true)
                ->where('escalation_level', 1)
                ->where('due_at', '<=', $level2Cutoff)],
        ];

        $counts = [];

        foreach ($passes as [$kind, $query]) {
            $counts[$kind->value] = $this->dispatchFor($query, $kind, $chunk);
        }

        if ($this->output->isVerbose()) {
            $this->components->info(sprintf(
                'SLA scan queued %d warning, %d breach and %d level-2 escalation job(s).',
                $counts[EscalationKind::Warning->value],
                $counts[EscalationKind::Breach->value],
                $counts[EscalationKind::LevelTwo->value],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Candidate ids come from the SLA range indexes (index-only, see
     * docs/PERFORMANCE.md). Deliberately no ORDER BY id / chunkById: that made
     * the optimizer walk the primary key over the whole table every minute.
     * Ids are small, so collecting a pass's candidates at once is cheap; jobs
     * are dispatched in --chunk sized batches in id order.
     *
     * @param  Builder<Ticket>  $query
     */
    private function dispatchFor(Builder $query, EscalationKind $kind, int $chunk): int
    {
        $ids = $query->toBase()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();

        foreach ($ids->chunk($chunk) as $batch) {
            foreach ($batch as $id) {
                EscalateTicketJob::dispatch($id, $kind);
            }
        }

        return $ids->count();
    }
}
