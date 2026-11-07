<?php

use App\Modules\Assignment\Services\LeastWorkloadStrategy;
use App\Modules\Assignment\Services\RoundRobinStrategy;

/*
|--------------------------------------------------------------------------
| Support Desk business settings
|--------------------------------------------------------------------------
|
| Every tunable business rule lives here so no value is hard-coded in
| Actions, Services, Jobs or views. Each value can be overridden from the
| environment. Editable SLA rules are later moved to the database; these
| values then act as defaults/fallbacks.
|
*/

return [

    /*
    | Human-readable ticket numbers, e.g. TKT-000042.
    */
    'ticket_prefix' => env('SUPPORT_TICKET_PREFIX', 'TKT'),
    'ticket_number_padding' => (int) env('SUPPORT_TICKET_NUMBER_PADDING', 6),

    /*
    | Business timezone. All timestamps are stored in UTC; this zone is used
    | only by the SLA calculator and when rendering dates in views.
    */
    'timezone' => env('SUPPORT_TIMEZONE', 'Asia/Dhaka'),

    /*
    | Working days use Carbon's dayOfWeek numbering (Sunday = 0 ... Saturday = 6).
    | Times are wall-clock times in the business timezone above.
    */
    'business_hours' => [
        'days' => [0, 1, 2, 3, 4],
        'start' => env('SUPPORT_BUSINESS_START', '09:00'),
        'end' => env('SUPPORT_BUSINESS_END', '18:00'),
    ],

    /*
    | Resolution targets per priority, in minutes of BUSINESS time.
    | warning_threshold: fraction of the target elapsed before a ticket is
    | flagged as "at risk" (0.8 = 80%).
    */
    'sla' => [
        'low' => (int) env('SUPPORT_SLA_LOW', 2880),
        'medium' => (int) env('SUPPORT_SLA_MEDIUM', 1440),
        'high' => (int) env('SUPPORT_SLA_HIGH', 480),
        'critical' => (int) env('SUPPORT_SLA_CRITICAL', 120),
        'warning_threshold' => (float) env('SUPPORT_SLA_WARNING_THRESHOLD', 0.8),
    ],

    /*
    | Escalation of breached tickets (sla:scan). Level 1 happens at the breach.
    | Level 2 happens when an active ticket is still open this many CLOCK
    | minutes after its due date (not business minutes: an overdue ticket is
    | overdue around the clock).
    */
    'escalation' => [
        'level2_after_minutes' => (int) env('SUPPORT_ESCALATION_LEVEL2_AFTER_MINUTES', 120),
    ],

    /*
    | Resolved tickets are closed automatically after this many days
    | (tickets:auto-close-resolved).
    */
    'auto_close_resolved_after_days' => (int) env('SUPPORT_AUTO_CLOSE_RESOLVED_DAYS', 3),

    /*
    | Read in-app notifications older than this are pruned (model:prune).
    */
    'notifications' => [
        'prune_read_after_days' => (int) env('SUPPORT_PRUNE_READ_NOTIFICATIONS_DAYS', 90),
    ],

    /*
    | CSV ticket import (ProcessTicketImport). queue_connection should be
    | imports-redis / imports-database (retry_after > job timeout) in real
    | environments; null means the default connection (tests use sync).
    */
    'import' => [
        'chunk_size' => (int) env('SUPPORT_IMPORT_CHUNK_SIZE', 500),
        'max_kb' => (int) env('SUPPORT_IMPORT_MAX_KB', 51200),
        'queue' => 'imports',
        'queue_connection' => env('SUPPORT_IMPORT_QUEUE_CONNECTION'),
        // Send newly created customers a password-reset link so they can log in.
        'welcome_new_customers' => (bool) env('SUPPORT_IMPORT_WELCOME_NEW_CUSTOMERS', false),
    ],

    /*
    | Ticket/reply attachments. Stored on a non-public disk and streamed
    | through an authorized controller action, never linked directly.
    */
    'attachments' => [
        'disk' => env('SUPPORT_ATTACHMENTS_DISK', 'private'),
        'max_kb' => (int) env('SUPPORT_ATTACHMENTS_MAX_KB', 5120),
        'max_files' => (int) env('SUPPORT_ATTACHMENTS_MAX_FILES', 5),
        'mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'docx', 'xlsx', 'csv'],
    ],

    /*
    | Auto-assignment strategy resolved by App\Modules\Assignment\Services.
    */
    'assignment' => [
        'strategy' => env('SUPPORT_ASSIGNMENT_STRATEGY', 'least_workload'),

        // Strategy name => App\Modules\Assignment\Services\AssignmentStrategy implementation.
        'strategies' => [
            'least_workload' => LeastWorkloadStrategy::class,
            'round_robin' => RoundRobinStrategy::class,
        ],

        // Weight of one active ticket in an agent's workload, by priority.
        'workload_weights' => [
            'critical' => 3,
            'high' => 2,
            'medium' => 1,
            'low' => 1,
        ],

        // Unassigned active tickets re-queued per scheduler run (oldest due first).
        'retry_batch_size' => (int) env('SUPPORT_ASSIGNMENT_RETRY_BATCH', 100),

        // Queue an AutoAssignTicket job (after commit) for every new ticket.
        'auto_on_create' => (bool) env('SUPPORT_ASSIGNMENT_AUTO_ON_CREATE', true),
    ],

    /*
    | Volumes for database/seeders/PerformanceSeeder (EXPLAIN measurements only;
    | never part of the normal seed). See docs/PERFORMANCE.md.
    */
    /*
    | Error tracker (admin page /admin/errors). Every log entry at or above
    | `level` (which includes every reported exception) and every 4xx response
    | is stored, grouped by fingerprint. `isolated` writes through a separate
    | connection so errors inside rolled-back transactions are kept.
    */
    'error_tracker' => [
        'enabled' => (bool) env('ERROR_TRACKER_ENABLED', true),
        'isolated' => (bool) env('ERROR_TRACKER_ISOLATED', true),
        'track_http' => (bool) env('ERROR_TRACKER_TRACK_HTTP', true),
        'retention_days' => (int) env('ERROR_TRACKER_RETENTION_DAYS', 30),
    ],

    'performance' => [
        'tickets' => (int) env('PERF_TICKETS', 100000),
        'replies' => (int) env('PERF_REPLIES', 300000),
        'attachments' => (int) env('PERF_ATTACHMENTS', 50000),
    ],

    /*
    | Requests per minute per user (per IP for guests). Exceeding a limit returns
    | 429 Too Many Requests. Named limiters are registered in AppServiceProvider.
    */
    'rate_limits' => [
        'tickets' => (int) env('SUPPORT_RATE_TICKETS', 10),
        'replies' => (int) env('SUPPORT_RATE_REPLIES', 30),
        'downloads' => (int) env('SUPPORT_RATE_DOWNLOADS', 60),
        'imports' => (int) env('SUPPORT_RATE_IMPORTS', 5),
    ],

];
