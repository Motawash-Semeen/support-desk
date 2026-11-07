# SLA rules and the business-hours calculator

## Rules

| Rule | Behaviour | Where |
|---|---|---|
| Target per priority | Resolution target in **business minutes**: low 2,880 (48 h), medium 1,440 (24 h), high 480 (8 h), critical 120 (2 h). Seeded from `config/support.php`, then edited by admins at `/admin/sla-settings` (table `sla_policies`), so no code change is needed. | `SlaPolicy`, `Admin\SlaSettingsController` |
| Business hours | One window per weekday in `SUPPORT_TIMEZONE` (default **Asia/Dhaka**, Sunday–Thursday 09:00–18:00, Friday and Saturday closed). Editable per weekday. | `business_hours`, `BusinessCalendar` |
| Holidays | Whole calendar days in the business timezone, added and removed by admins. | `holidays` |
| Warning | At 80% of the target (`SUPPORT_SLA_WARNING_THRESHOLD`), also counted in business minutes, giving `sla_warning_at`. | `BusinessHoursSlaCalculator` |
| Snapshot | A ticket stores `sla_policy_id`, `sla_minutes`, `sla_warning_at` and `due_at` when it is created. Later rule changes do not move existing deadlines unless an admin runs `php artisan sla:recalculate --open`, which has a `--dry-run` option. | `CreateTicket`, `RecalculateSla` |
| Priority change | Deadline recalculated from the **original `created_at`** with the new priority's target (no credit or penalty for the time already spent), recorded as an `sla_recalculated` activity. | `UpdateTicket`, `TicketSlaRecalculator` |
| Reopen | A reopened ticket gets a fresh target counted from the reopen moment. | `ChangeTicketStatus` |
| Status | Computed, never stored: `breached` (active, `due_at` < now), `approaching` (active, warning passed), `within_sla`, and for finished tickets `met` / `missed` (finish time ≤ `due_at`). The PHP version (one ticket) and the SQL version (lists, filters, dashboard) sit side by side in one class, and a test proves they agree on every boundary. | `SlaStatusResolver` |
| Monitoring | `sla:scan` runs every minute and queues one job per ticket for each transition: warning, breach (escalation level 1, plus auto-assign if unassigned), and level 2 (120 minutes past due, `SUPPORT_ESCALATION_LEVEL2_AFTER_MINUTES`). Each transition is a guarded UPDATE, so it happens exactly once. | `ScanSla`, `EscalateTicket` |

All timestamps are stored in UTC. The business timezone is used only inside the calculator and when formatting
dates in views and emails.

## Algorithm (`App\Services\Sla\BusinessCalendar`)

The calendar is a pure, immutable value object (no database, no clock, no config), built by `SlaPolicyRepository`
from the three tables and cached until an admin changes them. `addBusinessMinutes(start, minutes)` works like this:

1. Convert `start` to the business timezone.
2. **Move to the next opening:** if `start` is inside a working window, stay; if it is before opening, jump to that
   day's opening; if it is after closing, on a closed weekday or on a holiday, jump to the next working day's opening.
3. **Consume day by day:** available = closing − cursor. If what remains fits, the deadline is cursor + remaining.
   Otherwise subtract the available time and jump to the next working day's opening.
4. Windows are half-open `[opens, closes)`: a deadline that lands exactly on closing time stays on that day. A start
   at the closing minute counts from the next opening.
5. **Guard:** at most 366 days are scanned. A configuration with no working day at all throws
   `NoBusinessHoursConfigured` instead of looping forever.

Arithmetic is done in seconds, so a ticket opened at 17:00:30 keeps its 30 seconds. The cost is O(days spanned),
at most a handful of iterations for real targets, and no per-minute loop.

### Worked example: Thursday 5 PM, critical ticket

Business hours Sunday–Thursday 09:00–18:00 (Asia/Dhaka), critical target 120 minutes, warning at 80% = 96 minutes.
The ticket is created **Thursday 2026-11-05 17:00** Dhaka time (11:00 UTC).

| Step | Cursor | Window left | Remaining before | Remaining after |
|---|---|---|---:|---:|
| Start is inside Thursday's window | Thu 17:00 | 60 min (until 18:00) | 120 | 60 |
| Friday and Saturday are closed; next opening | Sun 09:00 | 540 min | 60 | 0 → **due Sun 10:00** |

- `due_at` = **Sunday 2026-11-08 10:00 Dhaka** (04:00 UTC), not "Thursday 19:00".
- `sla_warning_at`: 96 minutes = 60 on Thursday + 36 on Sunday → **Sunday 09:36**.
- If Sunday 2026-11-08 were a holiday, the deadline would move to **Monday 10:00**.

This exact case is a unit test (`tests/Unit/Sla/BusinessCalendarTest.php`), together with these boundaries:

- start before opening, at opening, at closing, and after closing;
- multi-day spans and weekend jumps;
- consecutive holidays;
- a deadline exactly at closing;
- zero minutes;
- no working days configured;
- conversion to and from UTC.

## Intentionally out of scope

- **No pausing in `waiting_for_customer`.** The clock keeps running while the agent waits for the customer. Pausing
  needs a time ledger per ticket (paused intervals) and re-computation on every status flip. See
  KNOWN_LIMITATIONS.md.
- **Single business timezone and one calendar for every team or category.** No per-customer or per-region calendars.
- **One target type**, resolution only. There is no separate first-response SLA; `first_response_at` is recorded
  but not enforced.
- **No partial days** such as a half-day holiday or a lunch break: one window per weekday.
- **No retroactive re-evaluation.** Changing rules does not rewrite the met/missed history of finished tickets.
