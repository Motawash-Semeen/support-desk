# Support Desk

A support ticket and SLA management system: customers open tickets, agents work them, and every deadline is
counted in business hours, with automatic assignment, escalation and notifications.

Laravel 12 · PHP 8.2+ · MySQL 8 · Blade + Tailwind + Alpine · Pest

- **How to use every screen (with screenshots):** [docs/UI_GUIDE.md](docs/UI_GUIDE.md)
- **For developers (reading path, packages, code line by line, all decisions):** [docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md)

**Contents:** [Requirements](#requirements) · [1. Run it locally](#1-run-it-locally) ·
[2. Database setup and migrations](#2-database-setup-and-migrations) ·
[3. Environment and configuration](#3-environment-and-configuration) ·
[4. Queues and the scheduler](#4-queues-and-the-scheduler) · [5. Running the tests](#5-running-the-tests) ·
[6. Architecture decisions and assumptions](#6-architecture-decisions-and-assumptions) ·
[Sample accounts](#sample-accounts) · [Troubleshooting](#troubleshooting)

---

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2 or newer | extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo` (and `pdo_sqlite` for the no-setup test fallback) |
| Composer | 2 | |
| Node.js | 20.19+ or 22.12+ | only to build the CSS/JS (Vite 7) |
| MySQL | 8 (or MariaDB 10.4+) | the `mysql` driver works with both |
| Redis | optional | production queue and cache; locally everything runs on the database |

---

## 1. Run it locally

```bash
git clone https://github.com/Motawash-Semeen/support-desk.git
cd support-desk

composer install                 # PHP packages
cp .env.example .env             # local settings (edit DB_* if your MySQL differs, see section 3)
php artisan key:generate         # encryption key for sessions and cookies

php artisan migrate --seed       # create the tables and the sample data (section 2)

npm install && npm run build     # build the CSS and JS once

composer run dev                 # web server + queue worker + scheduler + live logs + Vite
```

Open <http://localhost:8000> and sign in with one of the [sample accounts](#sample-accounts)
(password `password`).

`composer run dev` starts everything the app needs in one terminal (press Ctrl+C to stop all of it). To start
the pieces yourself instead, use separate terminals:

```bash
php artisan serve                                          # the website on http://localhost:8000
php artisan queue:work --queue=notifications,default       # emails, bell notifications, auto-assignment
php artisan schedule:work                                  # SLA scan every minute, retries, auto-close
php artisan queue:work imports-database --queue=imports --timeout=3600   # only needed for CSV imports
```

---

## 2. Database setup and migrations

**Create the databases** (once). The test database must be separate, because the tests empty its tables:

```sql
CREATE DATABASE support_desk         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE support_desk_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

If you skip this, `php artisan migrate` offers to create `support_desk` for you.

**Create the tables and sample data:**

```bash
php artisan migrate --seed          # tables + reference data + sample team, customers and 42 tickets
```

| Command | What it does |
|---|---|
| `php artisan migrate` | creates the tables only |
| `php artisan db:seed` | adds the data: categories, SLA targets, business hours (always), plus the sample team, customers and tickets (not in production) |
| `php artisan migrate:fresh --seed` | **drops every table** and rebuilds everything: use it to reset your local data |
| `php artisan migrate:status` | shows which migrations have run |

**Where the migrations are:** each feature module keeps its own tables in
`app/Modules/<Module>/database/migrations` (tickets, replies, attachments, SLA rules, activities, imports,
notifications), and the core tables (users, sessions, cache, jobs) are in `database/migrations`. Laravel runs
them all together, in date order. There is one migration per table, with its final columns and indexes.

**Sample data:** `database/seeders/TicketSeeder.php` creates the 42 tickets by running the app's real actions
over the past few weeks, so ticket numbers, deadlines, history and notifications are realistic. The ticket
texts are in `database/seeders/data/tickets.php`.

---

## 3. Environment and configuration

All settings are in `.env` (copied from `.env.example`). For local use, usually only the database lines need
changing.

### Settings you may need to change

| Setting | Default | What it is |
|---|---|---|
| `APP_URL` | `http://localhost:8000` | the address you open in the browser; the log viewer only works from this host and port |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | `127.0.0.1`, `3306`, `support_desk`, `root`, empty | your MySQL connection |
| `DB_TEST_CONNECTION` | `auto` | tests use MySQL if `DB_TEST_DATABASE` is reachable, otherwise SQLite in memory (`mysql` or `sqlite` to force one) |
| `DB_TEST_DATABASE` (+ `DB_TEST_HOST/PORT/USERNAME/PASSWORD`) | `support_desk_testing` | the separate test database |
| `QUEUE_CONNECTION` | `database` | where background jobs wait (`redis` in production) |
| `CACHE_STORE` | `database` | the cache, also used for scheduler locks (`redis` in production) |
| `MAIL_MAILER` | `log` | emails are written to `storage/logs/laravel.log`; set `smtp` and the `MAIL_*` lines for real email |
| `SESSION_DRIVER` | `database` | sessions are stored in the `sessions` table |

### Business settings (`config/support.php`)

Every business rule has a default in `config/support.php` and can be overridden in `.env`:

| Setting | Default | What it controls |
|---|---|---|
| `SUPPORT_TIMEZONE` | `Asia/Dhaka` | the business timezone; everything is **stored in UTC** and shown in this zone |
| `SUPPORT_BUSINESS_START` / `_END` | `09:00` / `18:00` | default business hours (admins can edit them per weekday) |
| `SUPPORT_SLA_CRITICAL` / `_HIGH` / `_MEDIUM` / `_LOW` | 120 / 480 / 1440 / 2880 | default resolution targets in **business minutes** (admins can edit them) |
| `SUPPORT_SLA_WARNING_THRESHOLD` | `0.8` | a ticket is "at risk" after 80% of its target |
| `SUPPORT_ESCALATION_LEVEL2_AFTER_MINUTES` | `120` | escalate again if still open this long after the deadline |
| `SUPPORT_AUTO_CLOSE_RESOLVED_DAYS` | `3` | resolved tickets close automatically after this many days |
| `SUPPORT_ASSIGNMENT_AUTO_ON_CREATE` | `true` | assign new tickets to an agent automatically |
| `SUPPORT_ATTACHMENTS_MAX_KB` / `_MAX_FILES` | `5120` / `5` | upload limits (5 MB, 5 files) |
| `SUPPORT_TICKET_PREFIX` / `_NUMBER_PADDING` | `TKT` / `6` | ticket numbers look like `TKT-2026-000042` |
| `SUPPORT_RATE_TICKETS` / `_REPLIES` / `_DOWNLOADS` / `_IMPORTS` | 10 / 30 / 60 / 5 | per-user requests per minute |
| `SUPPORT_IMPORT_CHUNK_SIZE` / `_MAX_KB` | `500` / `51200` | CSV import rows per transaction, max file size (50 MB) |

After changing `.env` while the app runs with cached config (`php artisan optimize`), run
`php artisan config:clear`.

**PHP upload limits:** for 5 MB attachments and 50 MB imports, PHP must allow them first, in `php.ini`:
`upload_max_filesize = 6M`, `post_max_size = 64M`.

**Production:** `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`,
`SUPPORT_IMPORT_QUEUE_CONNECTION=imports-redis`, real `MAIL_*` settings, then `composer install --no-dev -o`,
`npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`.

---

## 4. Queues and the scheduler

**Both are required.** The website works without them, but these features run in the background:

| Feature | Needs | What happens without it |
|---|---|---|
| Emails and bell notifications | queue worker (`notifications` and `default` queues) | they wait in the `jobs` table until a worker starts |
| Automatic assignment of new tickets | queue worker (`default` queue) | tickets stay in the Unassigned queue |
| SLA warnings, breaches, escalation | scheduler + queue worker | deadlines pass silently; nothing is escalated |
| Retrying assignment, auto-closing resolved tickets, cleanup | scheduler | not done |
| CSV import | the separate import worker | the import stays "pending" |

**Locally:** `composer run dev` starts a worker and the scheduler for you. Or run them yourself:

```bash
php artisan queue:work --queue=notifications,default
php artisan schedule:work
php artisan queue:work imports-database --queue=imports --timeout=3600    # CSV imports
```

**In production:** run the workers under a process manager (Supervisor or systemd) and add **one** cron entry
per server:

```cron
* * * * * cd /path/to/support-desk && php artisan schedule:run >> /dev/null 2>&1
```

**What the scheduler runs** (`routes/console.php`):

| When | Command | Purpose |
|---|---|---|
| every minute | `sla:scan` | queue SLA warnings, breaches (escalation level 1) and level-2 escalations; each happens once |
| every 5 minutes | `tickets:assign-unassigned` | retry auto-assignment for tickets nobody owns |
| daily 02:00 | `tickets:auto-close-resolved` | close tickets resolved 3+ days ago |
| daily 03:30 | `attachments:purge-orphans` | delete uploaded files left behind by failed saves |
| daily 04:00 | `model:prune` | delete read notifications older than 90 days |
| weekly | `queue:prune-failed` | delete failed-job records older than a week |

Each task uses `withoutOverlapping()` and `onOneServer()`, so it's safe with several servers (the cache store
must support locks: database or Redis).

**Failed jobs:** `php artisan queue:failed` lists them, `php artisan queue:retry all` retries them.

**Other useful commands:** `php artisan sla:recalculate --open --dry-run` (preview re-applying changed SLA rules
to active tickets; drop `--dry-run` to apply).

---

## 5. Running the tests

```bash
php artisan test                            # the whole suite
php artisan test --parallel                 # faster: one database per process
php artisan test tests/Feature/Tickets      # one folder (area)
php artisan test --filter="internal note"   # tests whose name contains the text
DB_TEST_CONNECTION=sqlite php artisan test  # force SQLite in memory (no setup at all)
vendor/bin/pint --test                      # code style check (vendor/bin/pint fixes it)
vendor/bin/phpstan analyse                  # static analysis (Larastan level 5)
```

- **Which database:** the first line of output says it. With MySQL reachable at `DB_TEST_DATABASE`
  (`support_desk_testing`), the tests use MySQL; otherwise SQLite in memory, so the tests always run on a fresh
  clone.
- **What's covered:** about 850 tests in `tests/`: `Unit` (pure logic such as the business-hours calendar),
  `Feature/<Area>` (each feature over HTTP: tickets, assignment, conversation, SLA, notifications, search,
  dashboard, import, activity, logs), `Feature/Authorization` and `Feature/Security` (who may do what, 404s for
  other people's tickets, every route checked), and `Concurrency` (20 real processes racing for one ticket, MySQL
  only).
- **Deterministic:** every test runs at a frozen time (Mon 2026-11-09 10:00 Dhaka); mail, file storage and
  outside HTTP are faked.
- **CI:** GitHub Actions (`.github/workflows/tests.yml`) runs Pint, Larastan and the full suite on MySQL 8 on
  every push and pull request.
- **Writing and changing tests:** see the developer guide, section 8.

---

## 6. Architecture decisions and assumptions

The full list, each with the alternatives considered and why they were rejected, is in
[docs/DEVELOPER_GUIDE.md, section 7](docs/DEVELOPER_GUIDE.md#7-decisions-and-the-alternatives-we-rejected).
The most important ones:

### Architecture

- **Feature modules.** The code is split into 8 modules in `app/Modules` (Tickets, Conversation, Assignment, Sla,
  Activity, Notifications, Dashboard, Imports), each with its own models, actions, controllers, views, routes and
  migrations (package: L5Modular).
- **Actions hold the business logic.** One class per task (`CreateTicket`, `ClaimTicket`, `AddReply`…), each in
  one database transaction. Controllers only check permission, validate, call one action and return a page.
- **Server-rendered pages** (Blade + Alpine), not a JavaScript SPA, so authorization stays on the server.
- **Events fire only after the database commits,** so no email goes out about a change that was rolled back.
  Listeners and notifications run on the queue.

### Data and rules

- **Ticket numbers** (`TKT-2026-000042`) come from a locked counter row inside the ticket's own transaction:
  unique, gap-free, restarting each year.
- **SLA deadlines count business minutes,** skipping nights, closed days and holidays. The deadline and warning
  time are stored on the ticket when it's created, in UTC, so lists, the dashboard and the scheduler use fast
  indexed comparisons.
- **Concurrency:** accepting a ticket is one conditional `UPDATE … WHERE assigned_agent_id IS NULL`. Exactly one
  agent wins; the other sees "just taken" (HTTP 409). Escalations use the same idea, so each happens exactly once.
- **Auto-assignment** picks the available agent with the lowest workload (weighted by priority) among those who
  cover the ticket's category, then the one who has waited longest.
- **History is append-only:** every change writes one entry with who, when, and old and new values; entries can't
  be edited or deleted.

### Permissions

- **Three roles in one column** (admin, agent, customer), with **role middleware** for role-only pages and
  **policies** for per-ticket rules. No permissions package.
- **Who sees what:** admins see every ticket; agents see their own tickets plus the unassigned queue; customers
  see their own. Any other ticket is a **404**, exactly like a missing one, so ticket numbers can't be probed.
- **Internal notes** are staff-only, enforced on the server for the page, emails and the timeline.
- Files are on a private disk and downloaded only through a permission check. Every route is audited by a test.

### Assumptions

- Business hours are **Sunday–Thursday, 09:00–18:00, Asia/Dhaka**; Friday and Saturday are closed (editable).
- The SLA is a **resolution** target only; first-response time is recorded but not enforced.
- The SLA clock **keeps running** while a ticket waits for the customer.
- Customers may choose any priority; staff can correct it.
- Resolved tickets close automatically after 3 days; a customer or the assigned agent can reopen a resolved
  ticket, but only an admin can reopen a closed one.
- One organisation, one timezone. Staff accounts come from the seeder; there's no user-management screen.
- Locally, emails go to the log file; production uses SMTP.

---

## Sample accounts

`php artisan migrate --seed` creates a support team, 14 customer businesses and 42 realistic tickets in every
state. **Every password is `password`.**

| Role | Name | Email |
|---|---|---|
| Admin | Farhana Haque | `farhana.haque@supportdesk.test` |
| Agent | Tanvir Ahmed (billing, account) | `tanvir.ahmed@supportdesk.test` |
| Agent | Nusrat Jahan (technical, bugs) | `nusrat.jahan@supportdesk.test` |
| Agent | Arif Hossain (technical, features, general) | `arif.hossain@supportdesk.test` |
| Agent | Sadia Islam (billing, general) | `sadia.islam@supportdesk.test` |
| Agent | Rakib Hasan (on leave: gets no new tickets) | `rakib.hasan@supportdesk.test` |
| Customer | Rashed Chowdhury, Chowdhury Textiles | `rashed@chowdhury-textiles.test` |
| Customer | Mahmudul Karim, Greenleaf Traders | `mahmudul.karim@greenleaf-traders.test` |

The other customers are in `database/seeders/UserSeeder.php`. All addresses use the reserved `.test` domain.

**Admin pages:** SLA settings `/admin/sla-settings`, CSV import `/admin/imports` (sample file at
`/admin/imports/sample`), logs `/admin/log-viewer`.

---

## Troubleshooting

| Problem | Fix |
|---|---|
| `SQLSTATE[HY000] [1049] Unknown database` | create the database (section 2) or let `php artisan migrate` create it |
| Styles missing / blank page styling | run `npm run build` (or keep `npm run dev` running) |
| New tickets never get an agent; no emails | start a queue worker (section 4) |
| No SLA warnings or escalations | start the scheduler (`php artisan schedule:work`) and a worker |
| CSV import stuck on "pending" | start the import worker: `php artisan queue:work imports-database --queue=imports --timeout=3600` |
| Log viewer says "Unauthenticated" | open the app at exactly `APP_URL`, or add your host to `LOG_VIEWER_API_STATEFUL_DOMAINS` |
| Tests run on SQLite instead of MySQL | create `support_desk_testing` or check the `DB_TEST_*` values |
| Changed `.env` has no effect | `php artisan config:clear` |
