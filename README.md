# Support Desk: Support Ticket & SLA Management System

Laravel 12 · PHP 8.2+ · MySQL 8 · Redis (queue + cache in production) · Blade + Tailwind + Alpine (Breeze) · Pest

Customers open tickets. Agents work them inside business-hours SLA deadlines that admins configure.

| Area | What the system does |
|---|---|
| Ticket numbering | Gapless, year-based numbers (`TKT-2026-000042`) |
| Assignment | Least-workload auto-assignment, with a compare-and-set write so two agents can never both take a ticket |
| Conversation | Public replies and internal notes that customers can never see, with private attachments |
| SLA | Warnings, breaches and two-level escalation through an idempotent scheduler |
| History and notifications | An append-only activity history, plus queued email and in-app notifications |
| Search and dashboards | FULLTEXT search, filters and role dashboards |
| Import | A resumable CSV import of 100k+ rows |

| Document | Contents |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Layers, the "create ticket" request lifecycle, event flow, ERD |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Every major decision: options considered, why, trade-off (grouped by area) |
| [docs/SLA.md](docs/SLA.md) | SLA rules, the business-hours algorithm, the Thursday 5 PM worked example |
| [docs/ASSIGNMENT.md](docs/ASSIGNMENT.md) | Auto-assignment strategy, tie-breakers, concurrency |
| [docs/PERFORMANCE.md](docs/PERFORMANCE.md) | EXPLAIN of every list, filter, scan and dashboard query on 100k tickets |
| [docs/SECURITY.md](docs/SECURITY.md) | Verified security checklist |
| [docs/TESTING.md](docs/TESTING.md) | Test strategy, coverage by spec section, how to run |
| [docs/KNOWN_LIMITATIONS.md](docs/KNOWN_LIMITATIONS.md) | Honest boundaries and the scale-up path |

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` (`pdo_sqlite` for the zero-setup test fallback)
- Composer 2, Node.js 20.19+ or 22.12+ (Vite 7)
- MySQL 8 (MariaDB 10.4+ also works with the `mysql` driver)
- Redis in production (queue, cache, locks). Not needed locally: `.env.example` uses the database queue and cache.

## Quick start (6 commands)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Open <http://localhost:8000>. `.env.example` points at a MySQL database `support_desk` on `127.0.0.1` as `root`
with an empty password; edit `DB_*` if yours differs. If the database does not exist, `migrate` offers to create
it (`php artisan migrate --seed --force` creates it without asking), or you can create it yourself:

```sql
CREATE DATABASE support_desk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE support_desk_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- for tests
```

For background work (assignment, emails, SLA scan), also start a worker and the scheduler (next sections), or
run everything at once with `composer run dev` (serve + queue listener + logs + Vite).

## Demo accounts

`php artisan migrate --seed` creates reference data (categories, SLA policies, Sunday–Thursday 09:00–18:00 business
hours in Asia/Dhaka) and, outside production, these accounts with about 200 tickets in every status and SLA state.
The password for every account is **`password`**.

| Role | Email | Notes |
|---|---|---|
| Admin | `admin@example.com` | SLA settings, import, assign/reassign, reopen closed tickets, dashboard of everything |
| Agent | `agent1@example.com` | Billing, Account & login, General inquiry |
| Agent | `agent2@example.com` | Technical support, Bug report |
| Agent | `agent3@example.com` | Technical support, Feature request, General inquiry |
| Customer | `customer1@example.com` … `customer5@example.com` | Own tickets only |

Mail is written to `storage/logs/laravel.log` locally (`MAIL_MAILER=log`).

## Workers and the scheduler

```bash
# local (database queue): one worker for everything
php artisan queue:work --queue=notifications,default
php artisan queue:work imports-database --queue=imports --timeout=3600   # CSV imports
php artisan schedule:work                                                # runs the scheduler every minute
```

Production (Redis, `QUEUE_CONNECTION=redis`, `SUPPORT_IMPORT_QUEUE_CONNECTION=imports-redis`):

```bash
php artisan queue:work redis --queue=notifications,default --tries=3 --backoff=30
php artisan queue:work imports-redis --queue=imports --timeout=3600
```

and exactly **one cron entry** per server (the scheduled commands use `onOneServer()` and `withoutOverlapping()`):

```cron
* * * * * cd /path/to/support-desk && php artisan schedule:run >> /dev/null 2>&1
```

| Schedule | Command | Purpose |
|---|---|---|
| every minute | `sla:scan` | Queue SLA warning, breach (escalation level 1) and level-2 jobs; each happens once |
| every 5 minutes | `tickets:assign-unassigned` | Retry auto-assignment, those due soonest first |
| daily 02:00 | `tickets:auto-close-resolved` | Close tickets resolved for 3+ days |
| daily 03:30 | `attachments:purge-orphans` | Delete files left by failed uploads |
| daily 04:00 | `model:prune` | Delete read notifications older than 90 days |
| weekly | `queue:prune-failed --hours=168` | Delete failed jobs older than a week |

**Failed jobs:** failures are logged with ticket and recipient context and kept in `failed_jobs`. Inspect them with
`php artisan queue:failed`, retry with `php artisan queue:retry all`, and remove handled ones with
`php artisan queue:forget <id>`.

## Tests and quality

```bash
php artisan test                 # whole suite: MySQL support_desk_testing if reachable, else SQLite in memory
php artisan test --parallel      # one database per process
vendor/bin/pint --test           # code style
vendor/bin/phpstan analyse       # Larastan level 5
```

The first output line says which database ran. `DB_TEST_CONNECTION=sqlite` forces the zero-setup SQLite run
(about 35 s). CI ([.github/workflows/tests.yml](.github/workflows/tests.yml)) runs Pint, Larastan and the
parallel suite against MySQL 8. See [docs/TESTING.md](docs/TESTING.md).

## Key decisions and assumptions

The full reasoning, with alternatives and trade-offs for each, is in [docs/DECISIONS.md](docs/DECISIONS.md)
(128 entries, grouped by area). The ones that shape the system:

**Architecture**
- Business logic lives in single-purpose Actions (`app/Actions`) and Services (`app/Services`). Controllers only
  authorize, validate and call one Action.
- Every multi-step write is one transaction, and events and jobs fire only after commit.

**Data access and authorization**
- **Roles:** one `role` column (admin, agent, customer) plus Policies; no permissions package.
- **Customers get 404, not 403,** for other customers' tickets, so ticket numbers cannot be probed. Ticket routes
  resolve through `Ticket::visibleTo()`.
- **Internal notes and replies share one table** with a `type` column. One visibility switch serves the page, the
  JSON, emails and the timeline.

**Tickets and assignment**
- **Ticket numbers** come from a locked per-year counter inside the creating transaction: unique, gapless on
  rollback, and they widen past 6 digits.
- **Concurrent accept** is a single guarded `UPDATE` (compare-and-set). The affected-row count decides the winner,
  and the loser gets 409.
- **Auto-assignment** goes to the least weighted workload among available agents who cover the category, with
  generalists included. Ties go to whoever was assigned least recently. See [docs/ASSIGNMENT.md](docs/ASSIGNMENT.md).

**SLA**
- **The deadline is computed once,** in business minutes (`BusinessCalendar`), and stored as `due_at` /
  `sla_warning_at`. Status checks are then plain indexed comparisons. Rules, hours and holidays are tables edited
  by admins. See [docs/SLA.md](docs/SLA.md).
- **Escalation is idempotent:** a scheduled scan queues one unique job per ticket, and each transition is a guarded
  `UPDATE` that can happen only once.

**Import and performance**
- **The import is resumable:** a queued job streams the file in 500-row chunks with bulk inserts and keeps a
  checkpoint after each committed chunk.
- **Performance was measured, not assumed:** EXPLAIN/ANALYZE on 100k tickets drove covering indexes and
  deferred-join pagination. See [docs/PERFORMANCE.md](docs/PERFORMANCE.md).

**Assumptions** (simplest reasonable option where the spec left room; each is recorded in DECISIONS):
- One business timezone (Asia/Dhaka) and one calendar for everyone.
- Resolution SLA only; `first_response_at` is recorded but not enforced.
- The SLA clock keeps running in "waiting for customer". A priority change recalculates from the original creation
  time, and a reopen restarts the clock.
- Customers may set any priority. Only admins reopen closed tickets, and customers can reopen resolved ones.
- Self-registration creates customers only. Staff accounts come from the seeder or `php artisan tinker`; there is no
  user-management screen (KNOWN_LIMITATIONS).
- Imported customers get no email unless enabled; they sign in through a password reset.

What is deliberately out of scope, and how to add it, is in
[docs/KNOWN_LIMITATIONS.md](docs/KNOWN_LIMITATIONS.md).

## Feature tour (the 17 spec sections)

| # | Spec section | Try it | Main code | More |
|---|---|---|---|---|
| 1 | Ticket Management | Customer → **New ticket**; open, edit, resolve, close, reopen; **Tickets** list tabs Active / Resolved & closed | `TicketController`, `app/Actions/Tickets/*`, `TicketStatus::canTransitionTo` | ARCHITECTURE.md |
| 2 | Ticket ID Generation | Every ticket gets `TKT-2026-000042` | `TicketNumberGenerator` (locked per-year counter, gapless on rollback, widens past 6 digits) | DECISIONS (Tickets) |
| 3 | Roles and Permissions | Log in as each demo account; as customer1 open a customer2 ticket URL → 404 | `TicketPolicy`, `AttachmentPolicy`, gates, `EnsureRole`, `Ticket::visibleTo` route binding | SECURITY.md |
| 4 | Ticket Assignment | Admin → **Assign** on the ticket page; create a ticket with a worker running → auto-assigned to the least-loaded specialist | `AssignTicket`, `AutoAssignTicket`, `LeastWorkloadStrategy`, `CandidateAgents` | ASSIGNMENT.md |
| 5 | Concurrent Ticket Assignment | Two agents click **Accept** in the unassigned queue → one wins, the other sees "just taken" (409) | `ClaimTicket` (single guarded UPDATE, affected rows = winner) | ASSIGNMENT.md |
| 6 | Ticket Conversation | Customer and agent reply; agent adds an **internal note** → invisible when logged in as the customer | `AddReply`, `Ticket::repliesVisibleTo`, `StoreReplyRequest` | SECURITY.md |
| 7 | Attachments | Attach files when creating or replying; download → policy-checked stream | `StoreAttachments`, `AttachmentRules`, `SafeUpload`, `AttachmentController` | SECURITY.md |
| 8 | SLA Management | SLA badges and due times on every ticket; admin → **SLA settings** to change targets | `SlaPolicyRepository`, `BusinessHoursSlaCalculator`, `SlaStatusResolver`, `Admin\SlaSettingsController` | SLA.md |
| 9 | Business Hours | Create a critical ticket on Thursday after 17:00 → due Sunday 10:00; edit hours and holidays in **SLA settings** | `BusinessCalendar` | SLA.md (worked example) |
| 10 | SLA Escalation | `php artisan sla:scan --verbose`; escalated badge, level and activity entries | `ScanSla`, `EscalateTicketJob`, `EscalateTicket` | SLA.md |
| 11 | Notifications | Bell icon and `storage/logs/laravel.log` for mail; workers process them off the request | `app/Notifications/*`, `app/Listeners/*` | ARCHITECTURE.md (event flow) |
| 12 | Search and Filtering | **Tickets** filter bar: ticket ID, keyword, status, priority, category, agent, customer, created date, SLA status, sort; paginated | `TicketFilter`, `FullTextQuery`, `TicketIndexRequest` | PERFORMANCE.md |
| 13 | CSV Import | Admin → **Import** → upload `resources/samples/ticket-import-sample.csv`; watch progress; download errors | `StartTicketImport`, `RunTicketImport`, `ImportTicketRows`, `CsvReader` | below |
| 14 | Dashboard | Staff **Dashboard**: open, unassigned, critical, breached, at risk, escalated, resolved, by priority, by agent; every number links to the filtered list | `DashboardStatsService` | PERFORMANCE.md |
| 15 | Activity / Audit History | Ticket page → **Activity** (newest/oldest): created, assigned, priority changed from → to, replied, escalated, status changed | `TicketActivityRecorder`, `TicketActivity` (append-only) | DECISIONS (Activity) |
| 16 | Database and Performance | `php artisan perf:explain` on the 100k-ticket data set | indexes in `database/migrations`, `selectListColumns`/`withListRelations`, strict Eloquent | PERFORMANCE.md |
| 17 | Automated Testing | `php artisan test` | `tests/` (Unit, Feature, Concurrency), CI workflow | TESTING.md |

## Configuration reference

All business settings are in [config/support.php](config/support.php), driven by the `SUPPORT_*` keys in
`.env.example`:

- ticket prefix and padding;
- business timezone and default hours;
- SLA targets and warning threshold;
- attachment limits;
- assignment strategy and workload weights;
- escalation level-2 delay;
- auto-close days;
- rate limits;
- import chunk size.

| Concern | Local | Production |
|---|---|---|
| Queue / cache | `database` | `redis` (`predis` or `phpredis`) |
| Mail | `log` | `smtp` (commented block in `.env.example`) |
| Files | attachments on the `private` disk (`storage/app/private`, never served by URL) | same, or S3 through `SUPPORT_ATTACHMENTS_DISK` |
| Time | stored in **UTC**; `SUPPORT_TIMEZONE` (Asia/Dhaka) is used only by the SLA calculator and views | same |
| Debug | `APP_DEBUG=true` | `APP_ENV=production`, `APP_DEBUG=false`; cookies secure by default |

Production deploy: `composer install --no-dev -o`, `npm ci && npm run build`, `php artisan migrate --force`,
`php artisan optimize` (config, routes, events and views cached; `env()` is only used in config files).

### Attachments and PHP upload limits

Server-side validation allows 5 files of 5 MB each. Whitelisted types are checked by extension **and** by sniffed
content. PHP must let the request through before Laravel can validate it:

```ini
upload_max_filesize = 6M
post_max_size = 32M        ; also above SUPPORT_IMPORT_MAX_KB (50 MB) for CSV imports
max_file_uploads = 20
```

### CSV ticket import

Admins import at **/admin/imports** (sample at **/admin/imports/sample**). The upload returns immediately; a queued
job processes the file in chunks of 500 rows (one transaction each), the status page shows live progress, failed
rows download as a CSV report, and a failed import resumes after the last committed chunk.

| Column | Required | Notes |
|---|---|---|
| `external_reference` | yes | Unique per source; re-importing is reported as a duplicate |
| `customer_email` | yes | Existing customer, or a customer account is created (no email unless `SUPPORT_IMPORT_WELCOME_NEW_CUSTOMERS=true`) |
| `subject` | yes | Max 255 characters |
| `description` | yes | Max 10,000 characters |
| `priority` | yes | `low`, `medium`, `high`, `critical` (any case) |
| `category` | yes | Category name or slug |
| `status` | no | Default `open`; `resolved` / `closed` also set the finished time |
| `assigned_agent_email` | no | Must be an agent |
| `created_at` | no | ISO 8601, for example `2026-11-01T10:00:00+06:00` |

### SLA maintenance

Tickets keep the SLA they were created with. To re-apply changed rules to active tickets:

```bash
php artisan sla:recalculate --open --dry-run
php artisan sla:recalculate --open
```

### Performance data set

```bash
# throwaway database; never your dev data
DB_DATABASE=support_desk_perf php artisan migrate:fresh --force
DB_DATABASE=support_desk_perf php artisan db:seed --class=PerformanceSeeder --force   # 100k tickets, 300k replies, 50k attachments
DB_DATABASE=support_desk_perf php artisan perf:explain                               # EXPLAIN/ANALYZE every main query
```
