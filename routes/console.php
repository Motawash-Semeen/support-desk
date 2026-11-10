<?php

use App\Models\Notification;
use App\Modules\Assignment\AssignmentService;
use App\Modules\Conversation\ConversationService;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\TicketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Commands: each calls one service method. The schedule below runs them.
|--------------------------------------------------------------------------
*/

Artisan::command('sla:scan {--chunk=200 : Jobs dispatched per batch}', function (SlaService $sla) {
    $counts = $sla->scan((int) $this->option('chunk'));

    if ($this->output->isVerbose()) {
        $this->components->info(vsprintf('SLA scan queued %d warning, %d breach and %d level-2 escalation job(s).', array_values($counts)));
    }

    return Command::SUCCESS;
})->purpose('Queue SLA warning, breach and level-2 escalation jobs');

Artisan::command('sla:recalculate
    {--open : Re-apply the current rules to all active (open, in progress, waiting) tickets}
    {--dry-run : Report how many tickets would change without saving}
    {--chunk=200 : Tickets loaded per batch}', function (SlaService $sla) {
    // --open is a required opt-in: this moves live deadlines.
    if (! $this->option('open')) {
        $this->components->error('Pass --open to re-apply the current SLA rules to active tickets. Resolved and closed tickets are never changed.');

        return Command::INVALID;
    }

    $dryRun = (bool) $this->option('dry-run');
    $result = $sla->recalculateActiveTickets($dryRun, (int) $this->option('chunk'));

    $this->components->info(sprintf('%s %d of %d active tickets.', $dryRun ? 'Would update' : 'Updated', $result['changed'], $result['checked']));

    return Command::SUCCESS;
})->purpose('Re-apply the current SLA rules to active tickets');

Artisan::command('tickets:assign-unassigned {--limit= : Maximum tickets to queue (default: support.assignment.retry_batch_size)}', function (AssignmentService $assignment) {
    $queued = $assignment->queueUnassigned((int) ($this->option('limit') ?? config('support.assignment.retry_batch_size')));

    $this->components->info("Queued auto-assignment for {$queued} unassigned ticket(s).");

    return Command::SUCCESS;
})->purpose('Queue auto-assignment for unassigned active tickets, oldest due first');

Artisan::command('tickets:auto-close-resolved {--days= : Days a ticket must stay resolved (default: support.auto_close_resolved_after_days)}', function (TicketService $tickets) {
    $days = max(1, (int) ($this->option('days') ?? config('support.auto_close_resolved_after_days')));
    $closed = $tickets->autoCloseResolved($days);

    $this->components->info("Closed {$closed} ticket(s) resolved for {$days}+ days.");

    return Command::SUCCESS;
})->purpose('Close tickets that have been resolved for a number of days');

Artisan::command('attachments:purge-orphans
    {--hours=24 : Only delete orphan files older than this}
    {--dry-run : List what would be deleted}', function (ConversationService $conversation) {
    $dryRun = (bool) $this->option('dry-run');
    $orphans = $conversation->purgeOrphanFiles((int) $this->option('hours'), $dryRun);

    if ($dryRun) {
        foreach ($orphans as $path) {
            $this->line("Would delete {$path}");
        }
    }

    $this->components->info(sprintf('%s %d orphan file(s).', $dryRun ? 'Would delete' : 'Deleted', count($orphans)));

    return Command::SUCCESS;
})->purpose('Delete attachment files that have no database row');

/*
|--------------------------------------------------------------------------
| Schedule: one cron entry runs it (locally: php artisan schedule:work):
|   * * * * * cd /path/to/support-desk && php artisan schedule:run >> /dev/null 2>&1
| withoutOverlapping() and onOneServer() need a cache store with locks.
|--------------------------------------------------------------------------
*/

// SLA warnings, breaches and level-2 escalation.
Schedule::command('sla:scan')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// Retry auto-assignment for unassigned tickets.
Schedule::command('tickets:assign-unassigned')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// Close tickets resolved for a few days.
Schedule::command('tickets:auto-close-resolved')
    ->dailyAt('02:00')
    ->withoutOverlapping(10)
    ->onOneServer();

// Files left on disk by rolled-back uploads.
Schedule::command('attachments:purge-orphans')
    ->dailyAt('03:30')
    ->withoutOverlapping(10)
    ->onOneServer();

// Old read notifications.
Schedule::command('model:prune', ['--model' => [Notification::class]])
    ->dailyAt('04:00')
    ->withoutOverlapping(10)
    ->onOneServer();

// Failed jobs older than a week.
Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->weekly()
    ->onOneServer();
