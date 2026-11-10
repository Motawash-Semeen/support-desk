<?php

/*
|--------------------------------------------------------------------------
| Support Desk business settings. Each value can be set from the environment.
| SLA targets, hours and holidays are edited in the app; these are the defaults.
|--------------------------------------------------------------------------
*/

return [

    /*
    | Ticket numbers, e.g. TKT-2026-000042.
    */
    'ticket_prefix' => env('SUPPORT_TICKET_PREFIX', 'TKT'),
    'ticket_number_padding' => (int) env('SUPPORT_TICKET_NUMBER_PADDING', 6),

    /*
    | Business timezone. Timestamps are stored in UTC; this is for deadlines and display.
    */
    'timezone' => env('SUPPORT_TIMEZONE', 'Asia/Dhaka'),

    /*
    | Working days: 0 = Sunday ... 6 = Saturday. Times in the business timezone.
    */
    'business_hours' => [
        'days' => [0, 1, 2, 3, 4],
        'start' => env('SUPPORT_BUSINESS_START', '09:00'),
        'end' => env('SUPPORT_BUSINESS_END', '18:00'),
    ],

    /*
    | Resolution targets in business minutes. warning_threshold: share of the target
    | after which a ticket is "at risk" (0.8 = 80%).
    */
    'sla' => [
        'low' => (int) env('SUPPORT_SLA_LOW', 2880),
        'medium' => (int) env('SUPPORT_SLA_MEDIUM', 1440),
        'high' => (int) env('SUPPORT_SLA_HIGH', 480),
        'critical' => (int) env('SUPPORT_SLA_CRITICAL', 120),
        'warning_threshold' => (float) env('SUPPORT_SLA_WARNING_THRESHOLD', 0.8),
    ],

    /*
    | Level 2 escalation: a ticket still open this many clock minutes after its due date.
    | (Level 1 happens at the breach.)
    */
    'escalation' => [
        'level2_after_minutes' => (int) env('SUPPORT_ESCALATION_LEVEL2_AFTER_MINUTES', 120),
    ],

    /*
    | Resolved tickets close automatically after this many days.
    */
    'auto_close_resolved_after_days' => (int) env('SUPPORT_AUTO_CLOSE_RESOLVED_DAYS', 3),

    /*
    | Read in-app notifications older than this are pruned.
    */
    'notifications' => [
        'prune_read_after_days' => (int) env('SUPPORT_PRUNE_READ_NOTIFICATIONS_DAYS', 90),
    ],

    /*
    | CSV import. queue_connection: imports-redis / imports-database in real
    | environments; null means the default connection.
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
    | Attachments: stored on a private disk and streamed after a permission check.
    */
    'attachments' => [
        'disk' => env('SUPPORT_ATTACHMENTS_DISK', 'private'),
        'max_kb' => (int) env('SUPPORT_ATTACHMENTS_MAX_KB', 5120),
        'max_files' => (int) env('SUPPORT_ATTACHMENTS_MAX_FILES', 5),
        'mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'docx', 'xlsx', 'csv'],
    ],

    /*
    | Auto-assignment (AssignmentService::pickAgent).
    */
    'assignment' => [
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
    | Requests per minute per user (per IP for guests); over the limit is a 429.
    */
    'rate_limits' => [
        'tickets' => (int) env('SUPPORT_RATE_TICKETS', 10),
        'replies' => (int) env('SUPPORT_RATE_REPLIES', 30),
        'downloads' => (int) env('SUPPORT_RATE_DOWNLOADS', 60),
        'imports' => (int) env('SUPPORT_RATE_IMPORTS', 5),
    ],

];
