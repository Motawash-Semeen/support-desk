<?php

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
    | Auto-assignment strategy resolved by App\Services\Assignment.
    */
    'assignment' => [
        'strategy' => env('SUPPORT_ASSIGNMENT_STRATEGY', 'least_workload'),
    ],

];
