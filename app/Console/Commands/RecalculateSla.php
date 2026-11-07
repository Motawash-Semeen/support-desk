<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\Sla\TicketSlaRecalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-applies the CURRENT SLA rules to active tickets, counted from each
 * ticket's original created_at. Resolved and closed tickets always keep their
 * snapshot so met/missed history never changes. --open is required as an
 * explicit opt-in, since this moves live deadlines.
 */
class RecalculateSla extends Command
{
    protected $signature = 'sla:recalculate
        {--open : Re-apply the current rules to all active (open, in progress, waiting) tickets}
        {--dry-run : Report how many tickets would change without saving}
        {--chunk=200 : Tickets loaded per batch}';

    protected $description = 'Re-apply the current SLA rules to active tickets';

    public function handle(TicketSlaRecalculator $recalculator): int
    {
        if (! $this->option('open')) {
            $this->components->error('Pass --open to re-apply the current SLA rules to active tickets. Resolved and closed tickets are never changed.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $checked = 0;
        $changed = 0;

        Ticket::query()
            ->active()
            ->select(['id', 'priority', 'status', 'created_at', 'sla_policy_id', 'sla_minutes', 'sla_warning_at', 'due_at'])
            ->chunkById(max(1, (int) $this->option('chunk')), function ($tickets) use ($recalculator, $dryRun, &$checked, &$changed): void {
                foreach ($tickets as $ticket) {
                    $checked++;

                    if ($dryRun) {
                        $changed += $recalculator->preview($ticket, $ticket->created_at) === null ? 0 : 1;

                        continue;
                    }

                    // One short transaction per ticket: lock, re-check, save.
                    $changed += DB::transaction(function () use ($ticket, $recalculator): int {
                        $locked = Ticket::query()->lockForUpdate()->find($ticket->id);

                        if ($locked === null || ! $locked->isActive()) {
                            return 0;
                        }

                        if (! $recalculator->apply($locked, $locked->created_at, null, 'rules re-applied')) {
                            return 0;
                        }

                        $locked->save();

                        return 1;
                    });
                }
            });

        $this->components->info(sprintf(
            '%s %d of %d active tickets.',
            $dryRun ? 'Would update' : 'Updated',
            $changed,
            $checked,
        ));

        return self::SUCCESS;
    }
}
