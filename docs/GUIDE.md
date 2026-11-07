# Codebase guide

How the code is organised, where each thing lives, and where to go when you want to change something.
Decisions and their reasons are in [DECISIONS.md](DECISIONS.md). Query measurements on 100k tickets are in
[PERFORMANCE.md](PERFORMANCE.md).

## 1. The big picture

A Laravel 12 app with server-rendered Blade pages. The code is split into **modules**: one folder per feature,
in `app/Modules` (package: L5Modular). Anything that is not a feature, such as users, login, layouts and shared
helpers, stays in Laravel's normal folders.

Every request follows the same path:

```
route (module routes/web.php)
  → middleware: auth, verified, role:admin|agent
  → Controller        thin: authorize + validate + call one Action + return a view/redirect
      → FormRequest   validation rules
      → Policy / Gate who may do it
      → Action        the actual work, inside DB::transaction
          → Services, Models, ActivityRecorder
          → Event (after commit) → queued Listener → Notification (mail + bell)
```

Rule of thumb: **business logic is in Actions and Services, never in controllers or views.**

## 2. Folder map

```
app/
  Modules/                     one folder per feature (see section 3)
  Models/User.php              users (role: admin | agent | customer)
  Models/Notification.php      in-app notifications (bell)
  Enums/Role.php
  Http/Controllers/            login/registration (Auth/*), profile, /dashboard redirect
  Http/Middleware/             EnsureRole (role:...), SecurityHeaders (CSP and other headers)
  Providers/AppServiceProvider.php
                               gates, policies, rate limits, {ticket} route binding, strict Eloquent
  Support/                     small shared helpers: BusinessTime (date display), FileSize, DeferredPagination
  View/Components/             <x-app-layout>, <x-guest-layout>
  Console/Commands/            perf:explain (dev only, see PERFORMANCE.md)

bootstrap/app.php              middleware, exception handling, module listener/command discovery
config/support.php             ALL business settings (SLA defaults, business hours, attachments, assignment, import)
routes/web.php                 core routes only (home, /dashboard, profile); auth.php = login routes
routes/console.php             the scheduler (what runs every minute / day)

resources/views/
  layouts/                     app shell: app.blade.php, navigation.blade.php (sidebar), guest.blade.php
  components/                  shared UI pieces: <x-badge>, <x-card>, <x-icon>, <x-stat-card>, buttons, inputs
  auth/, profile/              login, register, profile pages
  errors/                      403, 404, 419, 429, 500, 503 and the 4xx/5xx fallbacks
  welcome.blade.php            landing page (only the banner is shown; the other sections are commented out)
resources/css/app.css          design tokens and component classes (btn, card, field-input, table …)
tailwind.config.js             colours: brand (teal) and ink (slate)

database/factories/            test data factories for every model
database/seeders/              reference data, the support team and customers (UserSeeder), and realistic
                               sample tickets (TicketSeeder, texts in seeders/data/tickets.php). TicketSeeder
                               replays the real Actions over past time, so numbers, SLA, history and
                               notifications are exactly what the app would have produced
tests/                         Pest tests, grouped by feature (section 7)
```

## 3. The modules

Every module uses the same layout, so once you know one you know them all:

```
app/Modules/Tickets/
  Models/              Eloquent models (relationships, casts, scopes)
  Enums/               statuses, priorities …
  Actions/             one class per use case: CreateTicket, ResolveTicket …
  Services/            reusable logic used by several Actions
  Http/Controllers/    thin controllers
  Http/Requests/       validation (FormRequest)
  Http/Resources/      JSON shape of a model
  Policies/            who may do what
  Events/              raised after a change is committed
  Jobs/                queued work
  Console/Commands/    artisan commands (run by the scheduler)
  resources/views/     Blade views, used as view('Tickets::index')
  routes/web.php       this module's URLs
  database/migrations/ this module's tables
```

| Module | What it does | Key files |
|---|---|---|
| **Tickets** | create, view, edit, resolve, close, reopen; ticket numbers; search and filters | `Actions/CreateTicket.php`, `Models/Ticket.php`, `Enums/TicketStatus.php` (allowed transitions), `Services/TicketNumberGenerator.php`, `Filters/TicketFilter.php`, `Policies/TicketPolicy.php` |
| **Conversation** | public replies, internal notes, attachments | `Actions/AddReply.php`, `Actions/StoreAttachments.php`, `Policies/AttachmentPolicy.php`, `Rules/SafeUpload.php` |
| **Assignment** | accept, assign, release; automatic assignment | `Actions/ClaimTicket.php` (the race-safe write), `Services/LeastWorkloadStrategy.php`, `Jobs/AutoAssignTicket.php` |
| **Sla** | deadlines in business hours, warnings, breaches, escalation; admin SLA settings | `Services/BusinessCalendar.php` (the maths), `Services/BusinessHoursSlaCalculator.php`, `Services/SlaStatusResolver.php`, `Actions/EscalateTicket.php`, `Console/Commands/ScanSla.php` |
| **Activity** | the ticket's history (audit trail) | `Services/TicketActivityRecorder.php`, `Models/TicketActivity.php`, `Enums/ActivityType.php`, `resources/views/components/ticket-timeline.blade.php` |
| **Notifications** | emails and bell notifications for ticket events | `Listeners/Notify*.php` (who gets notified), `Notifications/*.php` (what they get), `Http/Controllers/NotificationController.php` |
| **Dashboard** | the staff dashboard numbers | `Services/DashboardStatsService.php` |
| **Imports** | CSV import of tickets (queued, resumable) | `Actions/RunTicketImport.php`, `Actions/ImportTicketRows.php`, `Support/CsvReader.php` |

Modules use each other directly. For example, `CreateTicket` calls the Sla calculator and the Activity recorder.

## 4. How the main features work

**Ticket number** (`Tickets/Services/TicketNumberGenerator.php`): a counter row per year in `ticket_sequences`,
locked and incremented inside the same transaction that creates the ticket. The result is `TKT-2026-000001`. If
the transaction rolls back, so does the counter, so numbers have no gaps and are never duplicated.

**Status changes** (`Tickets/Enums/TicketStatus.php`): `canTransitionTo()` lists every allowed move.
`ChangeTicketStatus` refuses anything else with `InvalidTicketTransition`.

**Concurrent accept** (`Assignment/Actions/ClaimTicket.php`): one guarded SQL statement,
`UPDATE tickets SET assigned_agent_id = ? WHERE id = ? AND assigned_agent_id IS NULL`. Exactly one agent's update
changes a row. The other agent gets a "someone else took it" message (HTTP 409).

**Auto-assignment** (`Assignment/Services/LeastWorkloadStrategy.php`): candidates are available agents who cover
the ticket's category, or generalists with no category. If nobody matches, every available agent is a candidate.
The ticket goes to the lowest weighted open workload (critical 3, high 2, medium and low 1). Ties go to whoever was
assigned least recently, then the lowest id. If nobody is available, the ticket waits in `/staff/unassigned`, and
`tickets:assign-unassigned` retries every 5 minutes.

**SLA deadline** (`Sla/Services/BusinessCalendar.php`): the target per priority is in business minutes (critical
2 h, high 8 h, medium 24 h, low 48 h; admins can edit them). The calendar skips nights, closed days and holidays.
Example: a critical ticket opened Thursday 17:00, with hours Sunday–Thursday 09:00–18:00, is due Sunday 10:00.
`sla_warning_at` is set at 80% of the target. Both are stored on the ticket when it is created, in UTC.

**SLA status** (`Sla/Services/SlaStatusResolver.php`): computed, never stored. Active tickets are within SLA,
approaching or breached; finished tickets met or missed the SLA. The same rules exist in PHP (one ticket) and in
SQL (lists and dashboard), side by side.

**Escalation** (`Sla/Console/Commands/ScanSla.php` → `Jobs/EscalateTicketJob.php` → `Actions/EscalateTicket.php`):
every minute, `sla:scan` queues a job for each ticket that reached its warning time, breached its deadline, or has
been breached for 2 more hours (level 2). Each step is a guarded UPDATE (`... WHERE breached_notified_at IS NULL`),
so it happens once, however often the scan runs.

**Activity history** (`Activity/Services/TicketActivityRecorder.php`): every Action that changes a ticket calls the
recorder in the same transaction. That covers created, assigned, reassigned, released, status and priority changes,
replies, internal notes, attachments, SLA warning and breach, escalation, and import. Each entry is one row in
`ticket_activities` with the type, the actor (null = system), old and new values, and a readable description such
as "Priority changed from Medium to High". The table is append-only: the model refuses updates and deletes. The
timeline on the ticket page is `<x-Activity::ticket-timeline>`. Customers do not see internal-note entries.

**Notifications** (`Notifications/Listeners/*`): events fire only after the database commit. A queued listener
decides who to notify, and the notification sends mail (on the `notifications` queue) and a bell entry. The person
who did the action is not notified. Internal notes never reach customers.

**Logs**: everything the app logs goes to `storage/logs/laravel.log`. Admins read, search and download the log
files at `/admin/log-viewer` (package opcodesio/log-viewer, settings in `config/log-viewer.php`). Visitors never
see internals: errors render the custom pages in `resources/views/errors`.

## 5. "I want to change …"

| I want to … | Go to |
|---|---|
| change SLA targets, business hours, holidays | the admin screen `/admin/sla-settings`; defaults in `config/support.php` |
| change the business timezone, attachment limits, auto-close days | `config/support.php` (each value has a `SUPPORT_*` env variable) |
| add a field to tickets | migration in `app/Modules/Tickets/database/migrations`, `$fillable` and `casts()` in `Models/Ticket.php`, rules in `Http/Requests/StoreTicketRequest.php` / `UpdateTicketRequest.php`, the form in `resources/views/partials/form.blade.php`, the factory in `database/factories/TicketFactory.php` |
| allow a new status transition | `Tickets/Enums/TicketStatus.php` → `canTransitionTo()` |
| change who can do something | `Tickets/Policies/TicketPolicy.php` (ticket actions); gates in `app/Providers/AppServiceProvider.php` |
| change who receives an email | the matching `Notifications/Listeners/Notify*.php` |
| change an email's text | `Notifications/Notifications/*Notification.php` and the template `Notifications/resources/views/mail/ticket.blade.php` |
| add an activity type | a case in `Activity/Enums/ActivityType.php` (label and customer visibility), then call the recorder from the Action |
| change the auto-assignment rule | `Assignment/Services/LeastWorkloadStrategy.php`, or a new strategy class plus an entry in `config/support.php` |
| change the ticket list, filters or search | `Tickets/Filters/TicketFilter.php` and `Tickets/resources/views/index.blade.php` / `partials/filter-bar.blade.php` |
| change dashboard numbers | `Dashboard/Services/DashboardStatsService.php` and `Dashboard/resources/views/index.blade.php` |
| change the sidebar | `resources/views/layouts/navigation.blade.php` |
| change colours or button styles | `tailwind.config.js` and `resources/css/app.css` (then `npm run build`) |
| change what runs on a schedule | `routes/console.php` |
| add a page to a module | route in the module's `routes/web.php`, a controller method, a view in its `resources/views` |
| add a new module | a folder in `app/Modules` with the layout above; add its migrations folder to `phpstan.neon`, and map any new policy in `AppServiceProvider::configureModules` |

Factories always stay in `database/factories`. L5Modular's loader for factories inside modules does not work with
current Laravel.

## 6. Security in one list

- Every controller action calls `$this->authorize()`. Routes add `auth`, `verified` and `role:` middleware.
- A customer asking for someone else's ticket gets **404**, the same as a missing ticket (`Ticket::visibleTo()`
  in the `{ticket}` route binding).
- Internal notes are filtered on the server for the page, JSON, emails and timeline.
- Uploads: whitelisted extension **and** sniffed content type, size and count limits, private disk, downloads only
  through `AttachmentPolicy`.
- Mass assignment: `$fillable` on every model. Roles cannot be set from any form.
- CSRF on every form, a strict Content-Security-Policy with a nonce (`SecurityHeaders`), and rate limits on
  writes and downloads.
- `tests/Feature/Security/RouteAuthorizationAuditTest.php` fails if any route has no authorization.

## 7. Tests

```bash
php artisan test                           # MySQL support_desk_testing if reachable, else SQLite in memory
DB_TEST_CONNECTION=sqlite php artisan test # force SQLite (no setup, ~30 s)
php artisan test tests/Feature/Sla         # one area
php artisan test --filter="internal note"  # by name
vendor/bin/pint --test                     # code style
vendor/bin/phpstan analyse                 # static analysis (Larastan level 5)
```

`tests/Feature/<Area>` matches the feature: Tickets, Assignment, Conversation, Sla, Notifications, Search,
Dashboard, Import, Activity, Logs, Authorization, Security, Database. `tests/Unit` holds pure logic
(calendar maths, enums, CSV reader) and `ArchitectureTest.php` (the layer rules above, checked automatically).
Time is frozen at Mon 2026-11-09 10:00 Asia/Dhaka in every test (`tests/TestCase.php`). Mail, storage and HTTP are
faked. CI (`.github/workflows/tests.yml`) runs everything on MySQL 8 on every push.

## 8. Known limitations

- The SLA clock keeps running while a ticket waits for the customer.
- One business timezone and one calendar for everyone; resolution SLA only (no first-response SLA).
- Keyword search covers subject and description, not replies.
- No user-management screen: staff accounts come from the seeder.
- Nobody is emailed or paged about errors; admins check the logs at `/admin/log-viewer`.
- Uploads are type-checked but not virus-scanned.
