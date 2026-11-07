# Testing

## How to run

```bash
php artisan test                         # whole suite (MySQL if reachable, else SQLite)
php artisan test --parallel              # one test database per process
php artisan test tests/Feature/Sla       # one area
php artisan test --filter="internal note"
DB_TEST_CONNECTION=sqlite php artisan test   # force SQLite :memory: (no setup at all)
DB_TEST_CONNECTION=mysql  php artisan test   # force MySQL (fails if unreachable)
vendor/bin/pint --test                   # code style
vendor/bin/phpstan analyse               # Larastan, level 5
php artisan test --coverage              # needs Xdebug or PCOV
```

**Database selection** (`tests/bootstrap.php`): with `DB_TEST_CONNECTION=auto` (the default), the suite
uses MySQL `DB_TEST_DATABASE` (default `support_desk_testing`) when it is reachable, otherwise SQLite
in memory. The first line of output says which one ran. The MySQL test database must be dedicated:
tests empty its tables.

**CI** (`.github/workflows/tests.yml`) runs `pint --test`, Larastan level 5 and
`php artisan test --parallel --processes=4` against a MySQL 8 service on every push to `main` and every
pull request.

**Speed.** The suite takes about 35 s on SQLite. On MySQL the time is dominated by the 10,000-row import
and by DDL (one `migrate:fresh` per process). On a stock XAMPP MariaDB, every DDL statement costs
0.2–0.4 s (16 MB buffer pool, Windows fsync), so the first migration alone takes about a minute. For a fast
local MySQL run, raise `innodb_buffer_pool_size` (for example to 256M) in XAMPP's `my.ini`, or use SQLite
for the inner loop and let CI run MySQL.

## What every test can rely on

`Tests\TestCase` gives every framework test the same deterministic world (D-114, D-115):

| Concern | How |
|---|---|
| Time | Frozen with `Carbon::setTestNow` at **Mon 2026-11-09 10:00 Asia/Dhaka**. Tests that need another moment `travelTo()`; nothing sleeps. Factories compute dates from `now()`. |
| Random data | Faker seeded from the test name, so a failure reproduces exactly. |
| Storage | `Storage::fake('private')` (attachments, imports). |
| HTTP | `Http::preventStrayRequests()`: any outgoing call fails the test. |
| Mail | In-memory `array` transport; recipient tests use `Notification::fake()`. |
| Queue | In-process `sync` (listeners and jobs run inline); dispatch tests use `Queue::fake()`. |
| Frontend | `withoutVite()`: no built assets needed. |
| Database | `RefreshDatabase` (one transaction per test). `commitTestTransaction()` is for the two tests that need real commits (D-116). |

Factory states used for readable setup include:

- tickets: `->critical()`, `->high()`, `->low()`, `->unassigned()`, `->assigned($agent)`, `->inProgress()`, `->resolved()`, `->closed()`, `->onTrack()`, `->approaching()`, `->breached()`, `->escalated()`, `->imported()`
- users: `->admin()`, `->agent()`, `->customer()`, `->unavailable()`

The one test that fakes nothing is `tests/Feature/EndToEnd/TicketCreationChainTest.php`. It follows a
real HTTP request through ticket creation and the after-commit event, then the auto-assign job, then mail
delivered to the transport, and asserts the exact recipients and subjects.

## Layout

```
tests/
  Unit/            pure logic: BusinessCalendar, SlaCalculator, SlaStatusResolver, TicketStatus,
                   ticket-number format, strategy ordering, CSV reader, FULLTEXT sanitiser, filenames, architecture
  Feature/
    Auth/          Breeze flows (login, registration, verification, passwords)
    Authorization/ policy matrix, route access by role, IDOR sweep, attachment policy
    Security/      route-authorization audit, rate limits, headers/CSP, mass assignment, escaping, env() usage
    Tickets/       create/update/resolve/close/reopen, numbering (uniqueness, rollback), priority -> SLA
    Assignment/    manual, accept, release, compare-and-set, auto-assign strategies, scheduler retry
    Conversation/  replies, internal-note secrecy (page, JSON, email, timeline), attachment validation and download
    Sla/           settings, policy cache, recalculation, status scopes, sla:scan idempotency, escalation
    Notifications/ recipients per event, queued delivery, in-app bell
    Search/        keyword and field filters, sort whitelist, query counts
    Dashboard/     dashboard numbers and query budget
    Import/        10,000-row import, resume, replay, HTTP endpoints
    Activity/      append-only audit trail and timeline
    EndToEnd/      the single un-faked chain
    Database/      schema, indexes, relationships, scopes, seeders, N+1 guards
    Bootstrap/     configuration, strict Eloquent, welcome page, page smoke test (every page × every role,
                   strict mode on, so any N+1 is a failure)
    Profile/       Breeze profile
  Concurrency/     20 real processes racing to claim one ticket (MySQL only)
```

## Coverage by spec section

The 17 sections of the assessment specification. The numbers are test cases, with each dataset row counted
separately.

| # | Spec section | Tests | Where | Key behaviours proven |
|---|---|---:|---|---|
| 1 | Ticket Management | 96 | `Tickets/TicketActionsTest` (13), `Tickets/TicketHttpTest` (36), `Unit/Enums/TicketStatusTest` (43), `Tickets/PriorityChangeSlaTest` (4) | create, view, update, resolve, close and reopen; allowed and forbidden status transitions; active vs resolved/closed lists; one activity per changed field |
| 2 | Ticket ID Generation | 16 | `Tickets/TicketNumberGeneratorTest` (10), `Unit/Tickets/TicketNumberFormatTest` (6) | `TKT-YYYY-000001`, sequential per year, year rollover, rollback gives the number back, contiguous bulk reservation, widens past the padding, unique index as safety net |
| 3 | Roles and Permissions | 466 | `Authorization/TicketPolicyMatrixTest` (376), `IdorTest` (21), `TicketAccessTest` (9), `AttachmentPolicyTest` (8), `RoleMiddlewareTest` (5), `Security/*` (20), `Auth/*` (20), `Profile/*` (7) | every role × ability × ownership; another customer's ticket, attachment or notification is a 404 identical to "missing" and changes nothing; every authenticated route is authorized (route audit); verified email enforced; rate limits; mass assignment |
| 4 | Ticket Assignment | 41 | `Assignment/AssignmentHttpTest` (14), `AutoAssignmentTest` (16), `Unit/Assignment/StrategyOrderingTest` (11) | manual assign, accept and release; least-workload weighting, specialists vs generalists, tie-breaks, round-robin, unavailable agents skipped, skip recorded once, retry schedule |
| 5 | Concurrent Ticket Assignment | 14 | `Assignment/ClaimTicketTest` (13), `Concurrency/ClaimTicketRaceTest` (1) | deterministic compare-and-set: exactly one winner, the loser gets 409; reassign and release never clobber a newer assignment; 20 real processes race on MySQL with one winner |
| 6 | Ticket Conversation | 39 | `Conversation/AddReplyTest` (14), `ReplyHttpTest` (19), `InternalNoteVisibilityTest` (6) | customer and agent replies; customers can only post public replies; a marker in an internal note never appears in the customer's page, JSON, email, notifications or timeline |
| 7 | Attachments | 20 | `Conversation/AttachmentSecurityTest` (6), `AttachmentDownloadTest` (6), `Unit/SafeFilenameTest` (8) (+ `AttachmentPolicyTest` under 3) | extension **and** content type whitelist, size and count limits, private disk, policy-checked download, internal-note files hidden from customers, nosniff + sandbox CSP, orphan purge |
| 8 | SLA Management | 39 | `Unit/Sla/SlaStatusResolverTest` (12), `Sla/SlaStatusScopeTest` (2), `Sla/SlaSettingsTest` (16), `SlaPolicyRepositoryTest` (5), `RecalculateSlaCommandTest` (4) | within / approaching / breached / met / missed with PHP and SQL in agreement; targets editable by admins without code changes; cache flushed on change; `sla:recalculate` |
| 9 | Business Hours | 46 | `Unit/Sla/BusinessCalendarTest` (37), `Unit/Sla/SlaCalculatorTest` (9) | Thursday 17:00 + 2 h = Sunday 10:00; nights, weekends, holidays, closing minute, seconds kept, UTC conversion, no-working-day guard |
| 10 | SLA Escalation | 26 | `Sla/EscalationTest` (20), `Sla/SlaScanTest` (6) | warning, breach and level 2 each happen exactly once (guarded UPDATE); repeated scans and replayed jobs change nothing; escalated flag, activity, notifications; unassigned breach triggers auto-assign |
| 11 | Notifications | 28 | `Notifications/NotificationRecipientsTest` (11), `NotificationDeliveryTest` (10 + 3 arch), `InAppNotificationsTest` (6), `EndToEnd/TicketCreationChainTest` (1) | exact recipients per event (created, assigned, agent/customer replied, resolved, SLA approaching/breached); the actor is not notified; nothing sent on rollback; every notification queued (mail on its own queue); real mail in the end-to-end test |
| 12 | Search and Filtering | 35 | `Search/TicketFilterTest` (27), `Unit/FullTextQueryTest` (8) | ticket number via the unique index, FULLTEXT keyword (committed rows on MySQL), LIKE fallback, injection-safe; status, priority, category, agent, customer, date and SLA filters; sort whitelist; pagination |
| 13 | CSV Import | 24 | `Import/TicketImportProcessingTest` (9), `TicketImportHttpTest` (9), `Unit/CsvReaderTest` (6) | 10,000 rows: exact success/failure counts, row-numbered errors, duplicates (in file and in DB), contiguous numbers, bounded memory; resume after crash; admin only; CSV-injection-safe error report |
| 14 | Dashboard | 6 | `Dashboard/DashboardStatsTest` (6) | every card's number per role (admin vs agent scope), fixed query budget, cache |
| 15 | Activity / Audit History | 22 | `Activity/ActionActivityTest` (14), `AppendOnlyTest` (4), `TimelineTest` (4) | every action writes exactly one typed entry with old and new values; entries cannot be updated or deleted; paginated timeline in both orders |
| 16 | Database and Performance | 52 | `Database/*` (43), `Bootstrap/StrictEloquentTest` (5), `Bootstrap/PageSmokeTest` (4) | schema, indexes, FK rules, relationships, scopes; constant query count for 3 vs 30 rows; list pages select only list columns; lazy loading fails tests; every page × every role renders without N+1. Volume measurements in docs/PERFORMANCE.md. |
| 17 | Automated Testing | 980 | the whole suite, plus `Unit/ArchitectureTest` (4) and `Bootstrap/ConfigurationTest` (4) | this document: risk-first priorities, deterministic setup, CI on MySQL 8 with Pint and Larastan |

## Deliberately not tested

| Not tested | Why |
|---|---|
| UI snapshots, CSS, Tailwind classes, JavaScript (Alpine) behaviour | Low risk and high churn; snapshots break on cosmetic changes without catching logic bugs. Pages are still requested in feature tests (status, visible and hidden content). |
| Real SMTP, Redis or S3 | Infrastructure, not our logic; transports are swapped for in-memory ones. Queue connections and retry settings are asserted as configuration. |
| The scheduler actually firing at wall-clock times | Laravel's responsibility; tests assert each command's cron expression, `withoutOverlapping` and `onOneServer`. |
| Breeze scaffolding beyond its own tests | Upstream code; kept its tests as a smoke check. |
| Load and throughput beyond the 10,000-row import | Needs production-like hardware; the import test asserts bounded memory and the query plans are checked with EXPLAIN (D-103). |
| Exact wording of every email | Only ticket number, subject, link and absence of secrets are asserted, so copy changes do not break tests. |

## Coverage percentage

`php artisan test --coverage` needs Xdebug or PCOV. Neither is installed in the development environment
used for this project, and CI runs with `coverage: none` for speed. To measure, install PCOV and run
`php artisan test --coverage --min=80`. The table above is the behavioural coverage map.
