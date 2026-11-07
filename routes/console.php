<?php

use App\Models\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
|
| One cron entry on ONE line per server runs everything below:
|
|   * * * * * cd /path/to/support-desk && php artisan schedule:run >> /dev/null 2>&1
|
| (locally: `php artisan schedule:work`). withoutOverlapping(10) and
| onOneServer() take cache locks, so the cache store must support locks
| (database or redis). The 10 is the lock expiry in minutes, in case a run
| dies without releasing it. The frequent jobs run in the background so a
| slow one never delays the others.
|
*/

// SLA warnings, breaches (escalation level 1) and level-2 escalation.
// The scan only queues EscalateTicketJob; guarded UPDATEs make repeats harmless.
Schedule::command('sla:scan')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// Retry auto-assignment for unassigned active tickets, oldest due first.
Schedule::command('tickets:assign-unassigned')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// Close tickets resolved for support.auto_close_resolved_after_days days.
Schedule::command('tickets:auto-close-resolved')
    ->dailyAt('02:00')
    ->withoutOverlapping(10)
    ->onOneServer();

// Files left on disk by rolled-back uploads.
Schedule::command('attachments:purge-orphans')
    ->dailyAt('03:30')
    ->withoutOverlapping(10)
    ->onOneServer();

// Read in-app notifications older than 90 days (App\Models\Notification::prunable).
Schedule::command('model:prune', ['--model' => [Notification::class]])
    ->dailyAt('04:00')
    ->withoutOverlapping(10)
    ->onOneServer();

// Failed jobs older than a week (inspect/retry them before that: see README runbook).
Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->weekly()
    ->onOneServer();
