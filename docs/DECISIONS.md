# Decisions

Every major decision, grouped by area. Each entry: **Decision / Options considered / Why this one / Trade-off.**
Numbers (D-001 …) are stable identifiers referenced from code comments and other documents; they record the
order in which decisions were made, not their importance. Superseded entries are kept and marked.

| Area | Entries |
|---|---|
| [Foundation and stack](#foundation-and-stack) | D-001, D-002, D-003, D-004, D-005, D-006, D-007, D-008, D-011, D-012, D-013, D-015, D-043, D-126, D-127, D-129, D-130, D-131, D-132, D-133, D-134, D-135, D-136, D-137, D-138 |
| [Database](#database) | D-016, D-017, D-018, D-019, D-020, D-021, D-022, D-023, D-024, D-025, D-027, D-028, D-030, D-031, D-055 |
| [Authorization and security](#authorization-and-security) | D-026, D-032, D-033, D-034, D-035, D-036, D-037, D-038, D-039, D-040, D-041, D-042, D-051, D-120, D-122, D-123, D-124, D-125 |
| [Tickets and lifecycle](#tickets-and-lifecycle) | D-044, D-045, D-046, D-029, D-047, D-048, D-049, D-052, D-053, D-054 |
| [Assignment and concurrency](#assignment-and-concurrency) | D-050, D-066, D-067, D-068, D-069, D-070, D-071, D-072, D-073, D-074 |
| [SLA and escalation](#sla-and-escalation) | D-057, D-058, D-059, D-060, D-061, D-062, D-063, D-064, D-065, D-091, D-092, D-093, D-094, D-095, D-096 |
| [Conversation and attachments](#conversation-and-attachments) | D-075, D-076, D-077, D-078, D-079, D-080, D-081, D-082, D-083 |
| [Activity and notifications](#activity-and-notifications) | D-084, D-056, D-085, D-086, D-087, D-088, D-089, D-090 |
| [Search, dashboard and performance](#search-dashboard-and-performance) | D-097, D-098, D-099, D-100, D-101, D-102, D-103, D-121, D-128 |
| [CSV import](#csv-import) | D-104, D-105, D-106, D-107, D-108, D-109 |
| [Testing and quality](#testing-and-quality) | D-009, D-010, D-014, D-110, D-111, D-112, D-113, D-114, D-115, D-116, D-117, D-118, D-119 |

## Foundation and stack

### D-001 Laravel 12 on PHP 8.2
- **Decision:** Laravel 12.x (latest release installable on PHP 8.2), PHP 8.2+.
- **Options considered:** Laravel 11; Laravel 12; newer majors requiring PHP 8.3+.
- **Why this one:** Satisfies "Laravel 11 or newer"; Laravel 12 has security support for longer than 11 and still runs on the PHP 8.2 installed locally.
- **Trade-off:** Newer majors are not usable until PHP is upgraded.

### D-002 Server-rendered Blade + Breeze instead of Inertia/Vue or a separate SPA
- **Decision:** Laravel Breeze, Blade stack, Tailwind.
- **Options considered:** Breeze Blade; Breeze/Inertia (Vue/React); a separate SPA consuming a JSON API.
- **Why this one:** The assessment is graded mostly on backend quality. Server-rendered pages keep the surface small, authorization stays server-side by default (no duplicated client-side rules, no token handling), and there is one codebase to review.
- **Trade-off:** Less polished, less interactive UX; full page reloads.

### D-003 Action classes instead of fat controllers or a Repository layer
- **Decision:** Business logic in `app/Actions/*` (one class per use case) and `app/Services/*` (reusable domain logic). Controllers only authorize, validate, call an Action, respond. *(Amended by D-131: Actions and Services now live in each module's `Actions` and `Services` folders. The rule is unchanged.)*
- **Options considered:** Logic in controllers; logic in models; Repository + Service layers; Actions + Services.
- **Why this one:** Each use case (`CreateTicket`, `AssignTicket`) is one testable class with exactly one transaction boundary, callable equally from controllers, jobs, commands and tests. A Repository over Eloquent adds an indirection layer that re-wraps the query builder without adding value in Laravel.
- **Trade-off:** More, smaller files; contributors must know the convention.

### D-004 All business tunables in `config/support.php`
- **Decision:** Ticket numbering, business timezone/hours, SLA targets and warning threshold, attachment limits and assignment strategy live in `config/support.php`, overridable via `SUPPORT_*` env vars.
- **Options considered:** Class constants; scattered literals; database settings table only; config file.
- **Why this one:** One place to change behaviour ("changing SLA rules should not need changes throughout the app"), cached by `config:cache`, testable with `config([...])`. Prompt 5 moves editable SLA rules to the database; the config values then become defaults.
- **Trade-off:** Changing config values needs a deploy (or env change + config cache clear) until the DB-backed rules exist.

### D-005 Redis for queue and cache in production, database queue locally
- **Decision:** `.env.example` sets `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` (production target); local `.env` may use `QUEUE_CONNECTION=database`. Redis client is `predis/predis`.
- **Options considered:** Database queue everywhere; Redis everywhere; SQS.
- **Why this one:** Redis is atomic and fast, supports cache locks and tags, and allows Horizon later. The database queue needs no extra service, so it stays as the local fallback. `predis` is pure PHP, so no `phpredis` extension is required on Windows dev machines or CI.
- **Trade-off:** One more production service to run; `predis` is slower than the `phpredis` extension (switch with `REDIS_CLIENT=phpredis` where the extension exists).

### D-006 Sessions in the database
- **Decision:** `SESSION_DRIVER=database`.
- **Options considered:** file, cookie, database, redis.
- **Why this one:** Works across multiple web nodes without sticky sessions, sessions can be inspected/revoked with SQL, and does not make login depend on the cache store being up.
- **Trade-off:** One write per request to the `sessions` table.

### D-007 Mail: `log` locally, SMTP in production
- **Decision:** `MAIL_MAILER=log` in `.env.example`; SMTP variables documented beside it for production.
- **Options considered:** log; Mailpit/Mailtrap; real SMTP.
- **Why this one:** No external service needed to run the app or tests; mails can be read in `storage/logs`.
- **Trade-off:** Mail rendering is not visually checked locally (use Mailpit if needed).

### D-008 Private `private` disk for attachments
- **Decision:** New `private` disk rooted at `storage/app/private`, `visibility => private`, `serve => false`. `FILESYSTEM_DISK=local` stays the default disk.
- **Options considered:** `public` disk with obscure filenames; the existing `local` disk; S3.
- **Why this one:** Files are never reachable by URL; every download goes through a controller action that runs the policy. `serve => false` stops Laravel 11+'s built-in signed local-file route from exposing the disk. The disk name is referenced only through `config('support.attachments.disk')`, so switching to S3 is a config change.
- **Trade-off:** Downloads go through PHP instead of the web server (acceptable at 5 MB per file).

### D-011 Local server is MariaDB 10.4, production target is MySQL 8
- **Decision:** Develop against the local MariaDB 10.4 (XAMPP) using the `mysql` driver; target MySQL 8 in `.env.example` and docs.
- **Options considered:** Install MySQL 8 locally; Docker/Sail; use the existing MariaDB.
- **Why this one:** No extra install; Laravel 12 supports MariaDB 10.3+ and the `mysql` driver works with both.
- **Trade-off:** MySQL-8-only features (functional indexes, `CHECK` enforcement differences, `JSON` storage type) must be avoided or tested on MySQL 8 in CI.

### D-012 Laravel app at the repository root
- **Decision:** The Laravel app "support-desk" lives at the repository root (`E:\Ticket-Management-App`), named via `APP_NAME` and `composer.json` `name`.
- **Options considered:** `support-desk/` sub-folder; repository root.
- **Why this one:** Clone → `composer install` works without changing directory; tools (Pint, Pest, CI) run from the root.
- **Trade-off:** The folder name differs from the app name.

### D-013 `notifications` table created during bootstrap
- **Decision:** Publish Laravel's `notifications` migration now.
- **Options considered:** Add it with the first notification; add it now.
- **Why this one:** Database notifications are part of the planned design, and the strict-mode test needs a real relation (`User::notifications`) to prove lazy loading throws.
- **Trade-off:** One table exists before any feature writes to it.

### D-015 Application timezone fixed to UTC in code
- **Decision:** `config/app.php` keeps `'timezone' => 'UTC'` (no `APP_TIMEZONE` override); the business zone is `config('support.timezone')`.
- **Options considered:** Business timezone as app timezone; UTC via env; UTC in code.
- **Why this one:** Storing UTC must not depend on an env value someone could change; conversion happens only in the SLA calculator and views.
- **Trade-off:** Views must convert explicitly (a helper will do this).

### D-043 `App\Support\BusinessTime` for display
- **Decision:** Views format timestamps with `BusinessTime::format()` (UTC → `config('support.timezone')`).
- **Options considered:** Carbon `->timezone()` calls scattered through views; storing local time.
- **Why this one:** One place for the conversion D-015 promised; storage and queries stay UTC.
- **Trade-off:** Views must use the helper rather than `->format()` directly.

### D-126 `.env.example` runs locally with only MySQL
- **Decision:** The example environment uses `QUEUE_CONNECTION=database`, `CACHE_STORE=database` and `SUPPORT_IMPORT_QUEUE_CONNECTION=imports-database`, with the Redis production values commented right beside them. It lists every `SUPPORT_*` key (rate limits, escalation, maintenance) and a commented production block (`APP_ENV=production`, `APP_DEBUG=false`, secure cookies). Secrets exist only in the real `.env`, and `env()` is read only in `config/*` (asserted by a test), so `php artisan optimize` (config, route, event and view caches) is safe.
- **Options considered:** Redis defaults in the example (D-005's production target), which fail on a fresh clone without Redis.
- **Why this one:** The six setup commands in the README work on a fresh clone with nothing but MySQL. Production still runs Redis (D-005).
- **Trade-off:** A production deploy must switch three values to Redis; the README's production section says so.

### D-127 Honest KNOWN_LIMITATIONS instead of pretending completeness
- *(Superseded by D-133: the limitations are now a short list in `docs/GUIDE.md`.)*
- **Decision:** `docs/KNOWN_LIMITATIONS.md` lists every deliberate boundary with its impact and how to lift it, followed by an ordered scale-up path. Examples:
  - the SLA clock is not paused while waiting for the customer;
  - one timezone;
  - PHP-streamed attachments;
  - FULLTEXT token rules;
  - a sequential import;
  - `'unsafe-eval'` in the CSP.
- **Options considered:** Leave limitations implicit; implement more enterprise features.
- **Why this one:** The spec explicitly says not every enterprise scenario is needed. Reviewers reward clear reasoning about boundaries, and a known limitation with a stated path is a design decision rather than a hidden bug.
- **Trade-off:** The document must be kept current as features land.

### D-129 Own design system instead of the Breeze/Laravel defaults
- **Decision:** The Breeze look is replaced throughout:
  - the Laravel logo, Figtree, gray cards, uppercase buttons and top navigation;
  - Laravel's `welcome`, error and pagination pages.

  In their place is a small design system:
  - a Tailwind theme (`brand` teal and `ink` slate, Inter and JetBrains Mono);
  - component classes in `resources/css/app.css` (`btn`, `field-input`, `card`, `table`, `ticket-no` and others);
  - Blade components (`icon`, `brand`, `card`, `badge` with status dots, `stat-card`, `empty-state`, `avatar`, `settings-section`).

  On top of that sit a dark sidebar shell with a mobile drawer, a split-screen auth layout, a product landing page, custom 403/404/419/429/500/503 pages and custom pagination. The fonts are self-hosted through npm (`@fontsource-variable/inter`, `@fontsource/jetbrains-mono`) and bundled by Vite, so the CSP's `font-src` and `style-src` allow only `'self'`.
- **Options considered:** Restyle Breeze in place; a UI kit (Flux, Filament or a Tailwind UI copy); keep Bunny Fonts and add its new CDN host (`cdn.jsdelivr.net`) to the CSP.
- **Why this one:** One small set of classes and components keeps every page consistent and makes later pages cheap to build. No licence and no extra dependency are needed beyond two font packages. Self-hosted fonts remove a third-party request on every page view and keep the CSP strict. The rewrite also fixed a real bug: Breeze's logout links used inline `onclick=` handlers, which the nonce CSP (D-124) blocks, so logging out from the menu did not work; logout is now a plain submit button.
- **Trade-off:** Every view was rewritten, so markup no longer matches Breeze upstream. Future Breeze updates to the auth views must be merged by hand.

### D-130 In-app error tracker fed by the logger
- *(Superseded by D-135: removed; the log files and opcodesio/log-viewer are the only log UI.)*
- **Decision:** Every error is stored in an `error_logs` table and shown to admins at `/admin/errors`. There are two feeds:
  - a Monolog handler (`App\Modules\Logs\Logging\ErrorTrackerHandler`, channel `tracker`), always part of the default `stack`, which stores every log entry at `ERROR_TRACKER_LEVEL` (default `error`) or above. This includes every exception Laravel reports and every explicit `Log::error()` (failed notifications, failed imports and the rest).
  - a global middleware (`TrackHttpErrors`) that stores 4xx responses, which Laravel never reports. 409 and 422 are normal outcomes, and 404s for files browsers probe on their own (`favicon.ico`, `robots.txt` and similar) are noise, so neither is stored.

  How `ErrorRecorder` stores them:
  - **Grouping:** errors are grouped by fingerprint (exception class + file + line, the log message, or the HTTP status) with an occurrence counter, first/last seen, and the details of the latest occurrence: URL, method, user, IP, trace and redacted context.
  - **Recurrence:** a resolved error that happens again is reopened automatically.
  - **Isolation:** it writes through its own database connection, so an error inside a transaction that then rolls back is still kept.
  - **Safety:** it never throws and never logs itself.
  - **Admin page:** filters (open/resolved/all, level, source, search), a detail page with stack trace and context, resolve/reopen, delete and "clear resolved", a sidebar badge, and a dashboard banner.
  - **Error pages:** the custom 403/404/419/429/500/503 pages, plus `4xx`/`5xx` fallbacks for every other status. Server-error pages show the entry's reference number, so a user can quote it and the admin can find it.
  - **Retention:** old rows are pruned daily (`model:prune`).
- **Options considered:** Sentry, Flare or Bugsnag (external SaaS, an account and a data-processing agreement); Laravel Telescope (a development tool that records far more than errors, and is not meant to run for everyone in production); a log-file viewer package (reads only the file, cannot group or resolve, and ships its own front end that needs CSP exceptions).
- **Why this one:** It meets "log every error and show it to the admin" with no external service or package, in the app's own design and authorization model. Grouping keeps the list readable during an incident instead of showing thousands of identical lines. The file log remains the complete raw record.
- **Trade-off:** No alerting (email or Slack) on new errors yet, and no cross-service tracing. KNOWN_LIMITATIONS lists the upgrade path, which is to ship errors to a hosted tracker. An error storm costs one upsert per occurrence on the logs connection.

### D-131 The whole app in L5Modular modules
- **Decision:** `artem-schander/l5-modular` organises the code into nine modules under `app/Modules`:
  - Tickets, Conversation, Assignment, Sla, Activity, Notifications, Dashboard, Imports and Logs;
  - each module keeps L5Modular's layout: `Models`, `Actions`, `Services`, `Http/{Controllers,Requests,Resources}`, `Events`, `Listeners`, `Jobs`, `Policies`, `Console/Commands`, `resources/views`, `routes/web.php` and `database/migrations`.

  The core stays in the standard folders: users, roles, auth, profile, the two security middlewares, providers, layouts, Blade components and shared helpers. Three settings make Laravel find classes outside its naming conventions:
  - `bootstrap/app.php` discovers listeners and commands in every module;
  - `AppServiceProvider::configureModules` maps `TicketPolicy` and `AttachmentPolicy`, and resolves factories by class name;
  - `phpstan.neon` lists each module's migrations so Larastan still knows model properties.

  Files were moved with `git mv`, so history follows them. Namespaces, imports, view names (`view('Tickets::index')`, `<x-Activity::ticket-timeline>`) and route names are unchanged in behaviour. Every URL, route name and middleware stack is the same as before. The architecture tests now cover the module namespaces and fail if code reappears in the old layer folders.
- **Options considered:** Keep the layer-first layout (`app/Actions`, `app/Services` …); nwidart/laravel-modules (one service provider, composer file and asset pipeline per module); move only the newest features into modules; L5Modular for everything.
- **Why this one:** It was requested, and it groups each feature's code (a ticket's model, Actions, views, routes and migrations) in one folder, which is easier to navigate than nine layer folders that each hold a slice of every feature. L5Modular is light: one provider that registers routes, views and migrations by convention, with no per-module boilerplate. Moving the whole app avoids a half-and-half layout.
- **Trade-off:** L5Modular 2.1 is no longer actively maintained. Its module-factory loader calls a class removed in Laravel 8, so factories stay in `database/factories`, and an architecture test stops anyone adding `database/factories` inside a module. It works with Laravel 12 because it only uses stable APIs (`loadViewsFrom`, `loadMigrationsFrom`, route groups). If it breaks on an upgrade, replacing it means about twenty lines in a provider. Modules depend on each other directly (Tickets uses Sla and Activity), so they are ownership boundaries, not separately deployable packages. Rows written before the move keep the old class names in `notifications.type` and in queued jobs, so drain the queues before deploying this change.

### D-132 opcodesio/log-viewer next to the in-app error log
- *(Amended by D-135: the in-app error log was removed, so the log viewer is now the only log UI. The hardening below is unchanged.)*
- **Decision:** `opcodesio/log-viewer` 3.x serves the raw log files at `/admin/log-viewer`, with a sidebar link and a **Raw log files** button on the error log. The in-app error log (D-130) stays the primary, grouped view. Hardening:
  - **Access:** the page and its API use the same `auth`, `verified` and `role:admin` middleware as the rest of `/admin`, plus the package's own `viewLogViewer` gate (admins).
  - **Files:** only `storage/logs`. The default Apache, Nginx and Homebrew paths were removed.
  - **CSP:** the page view is overridden in `resources/views/vendor/log-viewer` so both inline scripts carry the request's nonce. The global CSP stays unchanged. It already allows `'unsafe-eval'` (Alpine) and inline styles, which the viewer's Vue build needs.
  - **Tests:** `LogViewerAccessTest` covers access for guests, agents, customers and admins on the page and the API, and checks that every inline script carries the nonce. The route-authorization audit covers the package's routes too.
- **Options considered:** Replace the error tracker with the log viewer; add it alongside; another viewer such as rap2hpoutre/laravel-log-viewer (unmaintained, no API); no file viewer.
- **Why this one:** Requested, and confirmed after comparing the two: both are kept. They complement each other. The tracker groups, counts and resolves errors. The file viewer shows everything else in the log (info and warning lines, full context, other channels) without shell access to the server. Overriding one Blade view keeps the strict CSP instead of adding `'unsafe-inline'` scripts for every page.
- **Trade-off:** The view override has to be checked when the package is upgraded. The viewer's API trusts the session only for requests from `APP_URL`'s host and port (`LOG_VIEWER_API_STATEFUL_DOMAINS` adds others). Admins can download and delete log files from the browser, which is acceptable because they already hold the highest role.

### D-133 Fewer docs, one codebase guide, a smaller landing page
- **Decision:** The documentation is down to three files:
  - `README.md`: setup, demo accounts, workers, tests and admin pages;
  - `docs/GUIDE.md`: the folder and module map, how each feature works, a "where do I change X" table, security, tests and limitations;
  - `docs/DECISIONS.md` and `docs/PERFORMANCE.md`, without its per-query appendix.

  ARCHITECTURE, ASSIGNMENT, SLA, SECURITY, TESTING, KNOWN_LIMITATIONS and REVIEW_CHECKLIST were folded into the guide or dropped. The landing page shows only the header and hero banner; the features, "how it works", call-to-action and footer sections are kept in a Blade comment so they can be restored. Code nothing in the app called was removed: `SlaStatus::appliesToActiveTickets`, `Holiday::scopeBetweenDates`, both `isSystem()` methods, `Ticket::internalNotes`, `Ticket::isSlaBreached`, `ReplyType::isVisibleToCustomer`, the `reassign` policy ability (assignment is authorized by `assign`) and the `inspire` command.
- **Options considered:** Keep every document; one long README; a guide plus decisions.
- **Why this one:** Requested. A new developer needs one map of where things are and how to change them. Nine overlapping documents repeated each other and drifted. Unused methods look like features and mislead readers.
- **Trade-off:** Some depth is gone: the SLA worked-example table, the per-query EXPLAIN appendix and the scale-up path. All of it remains in git history. The performance tooling (`perf:explain`, `PerformanceSeeder`) and the migration history were kept unchanged.

### D-134 Realistic sample data, produced by replaying the real Actions
- **Decision:** The demo data was replaced with realistic sample data:
  - `UserSeeder`: a named team (one admin, five agents with specialities, one of them on leave) and 14 customer businesses, all on the reserved `.test` domain;
  - `TicketSeeder`: 42 tickets written as real support conversations (`database/seeders/data/tickets.php`).

  How `TicketSeeder` builds them:
  - Each ticket has a flow: new, stale, assigned, working, waiting, approaching, breached, resolved, late, closed or reopened.
  - For each ticket it plans a timeline. Agents act in business hours; customers write at any time.
  - It searches backwards for a creation time that puts every step in the past and leaves the ticket in its flow's state, checked against the real SLA calculator.
  - It then replays all steps in global time order through the real Actions (`CreateTicket`, `ClaimTicket`, `AddReply`, `UpdateTicket`, `ChangeTicketStatus`, `AssignTicket`, `EscalateTicket`), moving the clock to each step.
  - Between steps it applies the warnings, breaches and level-2 escalations that `sla:scan` would have made at that moment.
  - Listeners run inline, so bell notifications exist with the right times. Mail goes to the array mailer. Auto-assign and escalation jobs are faked, because the seeder plays their part. Notifications older than two days are marked read.
- **Options considered:** Keep factory/Faker data (lorem ipsum, random states written straight into columns); hand-write rows into the tables; replay the Actions.
- **Why this one:** Requested ("real-like data"). Replaying the Actions makes the data consistent by construction: gapless numbers in creation order, business-hours deadlines, one history entry per real change, reply-driven status changes, and auto-close at the scheduler's 02:00 run. Data written straight into columns can contradict the rules (for example a reply before the ticket existed, or a breach with no escalation). `SeederTest` checks that every ticket ends in its flow's state and that nothing is dated in the future.
- **Trade-off:** Seeding takes longer (it runs about 300 real transactions) and depends on the current time: the same seed run on another day gives different dates, though always the same states. A fixed set of 42 tickets is smaller than the old 200, so for volume testing use `PerformanceSeeder`.

### D-135 opcodesio/log-viewer as the only log UI; in-app error tracker removed
- **Decision:** The in-app error tracker (D-130) was removed: the `Logs` module (the `error_logs` table, `ErrorRecorder`, the `tracker` log channel, `TrackHttpErrors`, `/admin/errors`), the sidebar badge, the dashboard banner and the reference number on 500 pages. Errors are logged by Laravel's normal `stack` channel to `storage/logs`, and admins read them at `/admin/log-viewer`, which keeps its D-132 hardening. The custom error pages stay. The app now has eight modules.
- **Options considered:** Keep both (D-132); keep only the in-app tracker; keep only the log viewer.
- **Why this one:** Requested after comparing the two. One log tool is less code to maintain, and the file log already holds every error with full context.
- **Trade-off:** Lost compared with the in-app tracker:
  - grouping identical errors with a count, and resolving or reopening them;
  - the open-error badge and banner;
  - the reference number a user could quote from a 500 page;
  - recording 4xx responses (Laravel does not log them);
  - errors kept inside rolled-back transactions; the file log keeps these anyway.

  Finding one user's error now means searching the log viewer by time or URL.

### D-136 Ticket filter bar: tabs for scope, one toolbar, removable chips
- **Decision:** The ticket list filters were redesigned (`Tickets/resources/views/partials/filter-bar.blade.php`):
  - **Scope:** chosen only by the tabs (Active / Resolved & closed / All). Switching tabs keeps the search and the other filters but drops statuses, because each tab offers only its own statuses.
  - **Toolbar:** one row with search, a Filters toggle showing how many filters are applied, and a labelled sort. The sort direction is a button that names the order, such as "Soonest first" or "Highest first", and applies it at once.
  - **Applied filters:** shown as chips, each one removable, plus "Clear all", even while the panel is closed.
  - **Panel:** a three-column panel with chip toggles for status, priority and SLA, and a date range that no longer overflows.
  - **Short URLs:** empty fields are left out on submit, so shared URLs stay short.

  Two defects were fixed along the way:
  - `scope=all` now really lists everything; before, an empty scope fell back to Active.
  - Tailwind now scans module views and enums (`app/Modules/**`); since the move to modules, classes used only there could be missing from a fresh CSS build.
- **Options considered:** Keep the old bar with small fixes; a sidebar of filters; auto-apply on every change.
- **Why this one:** Requested ("filter system design is not good"). The old bar had two scope controls that could contradict each other, two unlabelled sort selects, statuses that made no sense for the tab, and no view of what was applied while the panel was closed. Multi-select chips still need an explicit Apply, so the panel keeps an Apply button, while single choices (sort, direction, tabs, chip removal) apply immediately.
- **Trade-off:** A small Alpine snippet disables empty fields on submit. Without JavaScript, the form still works but sends the empty fields.

### D-137 One migration per table; performance tooling removed
- **Decision:** Two simplifications.
  - **Migrations:** the five follow-up migrations were folded into the migrations that create their tables. The index changes from D-128 now sit in the tickets and notifications migrations, the users `(role, name)` index sits in the users migration, and each tickets index has a one-line comment saying which query it serves. The activity-type rename was dropped, because it only rewrote rows from an older version. The resulting schema is identical.
  - **Performance tooling:** `perf:explain` (`ExplainQueries`), the 100k-ticket `PerformanceSeeder` and the `support.performance` settings were removed. `docs/PERFORMANCE.md` keeps the measured results and says where the tools live in git history.
- **Options considered:** Keep the migration history; keep the tooling for future measurements.
- **Why this one:** Requested ("remove unnecessary code … simplify"). The app has never been deployed, so there is no production database whose migration history must be preserved. One migration per table is far easier to read than a create followed by an 88-line widen-and-drop. The tooling was development-only and about 550 lines.
- **Trade-off:** An existing local database must be rebuilt with `php artisan migrate:fresh --seed`. Repeating the 100k measurements means restoring the two files from git history (commit `9edc811`).

### D-138 One assignment rule instead of a pluggable strategy
- **Decision:** Removed the `AssignmentStrategy` interface, `RoundRobinStrategy`, the `support.assignment.strategy` / `strategies` settings and the container binding that chose between them. `AutoAssignTicket` uses `LeastWorkloadStrategy` directly. The SLA interfaces (`SlaRules`, `SlaCalculator`) stay, because tests swap in fakes through them: in-memory rules for unit tests and failing calculators to prove rollback.
- **Options considered:** Keep the strategy switch; remove only round-robin; remove the switch.
- **Why this one:** Requested ("simplify"). Round-robin existed only to show that the switch worked, and nothing ever selected it, so the interface, the config map and the factory closure were indirection without a second real use.
- **Trade-off:** A different assignment rule now means editing `LeastWorkloadStrategy` (or reintroducing an interface) instead of changing one setting.

## Database

### D-016 Status, priority, role, reply/activity type as strings cast to PHP enums
- **Decision:** `VARCHAR` columns cast to backed enums (`TicketStatus`, `TicketPriority`, `Role`, `ReplyType`, `ActivityType`, plus `ImportStatus` and `TicketSource`).
- **Options considered:** MySQL `ENUM`; lookup tables with FKs; strings + PHP enums.
- **Why this one:** Adding a status is a code change, not a blocking `ALTER TABLE` on a large table; values are readable in raw SQL and logs; PHP gets type safety and behaviour (`isActive()`, `allowedTransitions()`, `sortWeight()`).
- **Trade-off:** The database itself does not reject an unknown value; integrity relies on validation and the cast (an invalid stored value throws on read).

### D-017 Snapshot `sla_minutes`; store `due_at` and `sla_warning_at` on the ticket
- **Decision:** At creation (and on re-prioritisation) the ticket stores the policy id, the minutes in force, and the computed `due_at` / `sla_warning_at`.
- **Options considered:** Compute SLA state in PHP at read time; a separate SLA table; denormalised columns on `tickets`.
- **Why this one:** Dashboards and the escalation scanner filter with an indexed `WHERE status IN (...) AND due_at < now()` instead of computing row by row; later policy changes do not rewrite history.
- **Trade-off:** Changing a policy does not move existing deadlines (intended); re-prioritising must recompute both columns in the same transaction.

### D-018 `ticket_sequences` table for numbering
- **Decision:** One row per year (`year` PK, `last_number`), incremented under `SELECT ... FOR UPDATE` (implemented in Prompt 4). Format `TKT-YYYY-NNNNNN`.
- **Options considered:** Derive from the auto-increment id; `MAX(number)+1`; UUIDs; a counter table.
- **Why this one:** Correct per-year reset with no race: concurrent creators serialise on one row lock instead of reading the same `MAX()`. Auto-increment ids have gaps after rollbacks and do not reset yearly.
- **Trade-off:** One hot row per year under very high write rates (acceptable for a support desk; the lock is held for a single UPDATE).

### D-019 Replies and internal notes in one table with a `type` column
- **Decision:** `ticket_replies.type` = `public` | `internal_note`.
- **Options considered:** Two tables; one table with a type column.
- **Why this one:** One timeline query, one attachment relation, one index set, and visibility enforced in one place.
- **Trade-off:** Every customer-facing query must filter by type, so the filter is centralised in `Ticket::publicReplies()` (and a policy in a later prompt), backed by `INDEX(ticket_id, type)`.

### D-020 Purpose-built append-only `ticket_activities`
- **Decision:** Own table with `type`, `description`, JSON `old_values` / `new_values`, `created_at` only; the model throws on update; `actor_id` NULL = system.
- **Options considered:** `spatie/laravel-activitylog`; observers writing generic diffs; purpose-built table.
- **Why this one:** Timeline indexed by `(ticket_id, created_at)`, no polymorphic `subject_type/subject_id` joins, and full control over what is stored (no accidental logging of sensitive columns).
- **Trade-off:** Each Action must record its activity explicitly.

### D-021 `agent_category` pivot for specialisation
- **Decision:** Many-to-many between agents and categories, composite PK `(user_id, category_id)` plus reverse index `(category_id, user_id)`.
- **Options considered:** A single `category_id` on users; a pivot.
- **Why this one:** Agents can cover several categories; auto-assignment looks up "agents for this category" through the reverse index.
- **Trade-off:** One extra join in the assignment query.

### D-022 No soft deletes on tickets
- **Decision:** Tickets are closed, never deleted; no `deleted_at`.
- **Options considered:** SoftDeletes; hard delete; no deletion feature.
- **Why this one:** Audit-oriented system; deletion is not in the spec; avoids every query and index needing `deleted_at IS NULL`.
- **Trade-off:** Mistaken tickets must be closed (or removed manually by a DBA).

### D-023 Foreign-key delete rules
- **Decision:** `restrict` where history must survive (ticket → category, creator; reply → author; attachment → uploader; import → user); `cascade` for owned children (ticket → replies, attachments, activities; import → errors; pivot rows); `set null` where the reference is optional (ticket → assignee, SLA policy; activity → actor).
- **Options considered:** Cascade everywhere; no FKs; per-relation rules.
- **Why this one:** The database itself guarantees no orphaned rows and no silently lost history.
- **Trade-off:** Users with ticket history cannot be deleted. Breeze's "Delete account" now checks `User::hasTicketHistory()` and returns a validation error instead of a 500 from the FK.

### D-024 Ticket indexes and the query each serves
Verified with `EXPLAIN` on the seeded database: the breach scan uses `tickets_status_due_at_index`, a customer's list uses `tickets_created_by_created_at_index`, the agent workload count uses `tickets_assigned_agent_id_status_index` (all "Using index"), and search uses the FULLTEXT index.

| Index | Query it serves |
|---|---|
| `UNIQUE(ticket_number)` | Lookup by number; guarantees no duplicates. |
| `(status, due_at)` | `slaBreached()`: `status IN (active) AND due_at < now`; active tickets ordered by due date. |
| `(assigned_agent_id, status)` | `assignedTo()`, "my tickets", agent workload counts for least-workload assignment, dashboard by agent. |
| `(created_by, created_at)` | `visibleTo(customer)`: a customer's own list, newest first (filter and sort from the index). |
| `(priority, status)` | Priority filter; dashboard counts by priority and status. |
| `(category_id, status)` | Category filter; per-category dashboard; also backs the FK. |
| `(status, sla_warning_at)` | `slaApproaching()` / warning scanner: `status IN (active) AND sla_warning_at <= now`. |
| `(status, is_escalated, due_at)` | Escalation scanner: active, not yet escalated, overdue. |
| `(created_at)` | Staff "all tickets" list, newest first, paginated. Added beyond the brief because every sort column gets an index (rule 4). |
| `UNIQUE(source, external_reference)` | Import de-duplication; NULL references (web tickets) do not collide in MySQL. |
| `FULLTEXT(subject, description)` | `search()` keyword search (MySQL/MariaDB only). |

- **Options considered:** Single-column indexes per filter; composite indexes shaped per query; FK indexes only.
- **Why this one:** Composite indexes ordered equality-first then range/sort match the real WHERE/ORDER BY shapes; several are covering for counts.
- **Trade-off:** 11 secondary indexes slow writes slightly; tickets are read far more often than written.

### D-025 Other indexes
- **Decision:** `users(role, is_available, last_assigned_at)` instead of a lone `role` index (the leftmost prefix still serves `WHERE role = ?`; the full index serves available-agent selection ordered by last assignment). `categories(is_active, name)` for dropdowns. `ticket_replies(ticket_id, created_at)` and `(ticket_id, type)`. `ticket_activities(ticket_id, created_at)` and `(type, created_at)`. `attachments(ticket_id)` and `(ticket_reply_id)`. `ticket_imports(user_id, created_at)` and `(status, created_at)`. `ticket_import_errors(ticket_import_id, row_number)` instead of a lone `ticket_import_id` index (same prefix, and returns errors in file order). `holidays.date` and `business_hours.weekday` are unique.
- **Options considered:** Exactly the indexes in the brief; composite supersets.
- **Why this one:** A composite whose leftmost column is the requested one serves the same lookups plus the sort, with one index instead of two.
- **Trade-off:** Slightly wider index entries.

### D-027 FULLTEXT only on MySQL; LIKE fallback elsewhere
- **Decision:** The migration adds `FULLTEXT(subject, description)` only for `mysql`/`mariadb`; `Ticket::search()` uses `whereFullText` there and an escaped `LIKE` otherwise.
- **Options considered:** FULLTEXT only (breaks SQLite tests); LIKE only (no index); Scout/Meilisearch.
- **Why this one:** SQLite keeps migrating and searching; MySQL gets an indexed search with no extra service.
- **Trade-off:** InnoDB FULLTEXT does not see uncommitted rows, so with `RefreshDatabase` (one transaction per test) the MySQL test asserts the generated `MATCH ... AGAINST` SQL rather than results. Natural-language mode ignores words shorter than `innodb_ft_min_token_size` (3).

### D-028 Factories and demo seed
- **Decision:** Factories produce internally consistent timelines (assigned → first response → resolved → closed) through closures evaluated after states merge; `updated_at` is defined last so it can read the workflow columns. Factory SLA dates are a wall-clock approximation (`created_at + sla_minutes`); real tickets use the business-hours calculator. The demo seeder creates 200 tickets in fixed proportions (on-track, approaching, breached, escalated, unassigned, every status), bulk-inserts replies and activities in chunks of 500, syncs `ticket_sequences` to the highest seeded number, and runs in one transaction. Demo users/tickets are never seeded in production; reference seeders are idempotent (`updateOrCreate`).
- **Options considered:** Purely random data; fixed fixtures; proportioned factory states.
- **Why this one:** The dashboard demo always shows every state, and tests can request any state by name.
- **Trade-off:** Factory ticket numbers come from an in-process counter, not `ticket_sequences`; tests of the real generator must not rely on factory-created numbers.

### D-030 Small schema choices beyond the brief
- **Decision:** `categories` has timestamps; `business_hours`, `holidays` and `ticket_sequences` have none; `attachments.stored_name` is unique; `ticket_import_errors.row_number` keeps its name (reserved in MySQL 8, safe because Laravel always backtick-quotes identifiers).
- **Options considered:** Timestamps on every table; a non-unique `stored_name`; renaming `row_number`.
- **Why this one:** Timestamps only where they carry meaning; a unique on-disk name stops two rows pointing at one file.
- **Trade-off:** Hand-written raw SQL must quote `row_number`.

### D-031 `selectListColumns()` separate from `withListRelations()`
- **Decision:** `withListRelations()` eager loads `creator:id,name`, `assignee:id,name`, `category:id,name`; `selectListColumns()` limits ticket columns to `Ticket::LIST_COLUMNS` (no `description` TEXT).
- **Options considered:** One combined scope; two composable scopes.
- **Why this one:** Lists use both; detail pages reuse the relations without losing columns. Under strict mode, reading an unselected column throws, so a list needing more columns fails loudly in tests. A test proves 3 rows and 30 rows cost the same 5 queries.
- **Trade-off:** Callers combine the two scopes.

### D-055 Factory numbers in a separate range; demo seed uses the real generator
- **Decision:** `TicketFactory` numbers start at 900001 per year. `DemoTicketSeeder` replaces them with numbers from `TicketNumberGenerator::reserve()`, one block per year in creation order, so the sequence table ends exactly where new tickets continue.
- **Options considered:** Factories calling the real generator (locks in every test); random numbers.
- **Why this one:** Factory-made and generator-made tickets can share a test database without colliding on the unique index.
- **Trade-off:** Factory numbers look unusual (`TKT-2026-900123`) in test output.

## Authorization and security

### D-026 Mass-assignment policy
- **Decision:** `User::$fillable` excludes `role`, `is_available`, `last_assigned_at` (changed only through Actions via `forceFill`). Ticket `$fillable` lists the columns Actions write; FormRequests whitelist user input and Actions map validated fields explicitly. Strict mode makes any non-fillable key throw instead of being dropped.
- **Options considered:** `$guarded = []`; minimal fillable everywhere; explicit fillable plus explicit mapping.
- **Why this one:** Role escalation through an extra form field is impossible even if a controller passes too much.
- **Trade-off:** Seeders and role-changing Actions must use `forceFill`.

### D-032 Single role column + Policies instead of spatie/laravel-permission
- **Decision:** `users.role` (enum `Role`) plus Laravel Policies and two Gates.
- **Options considered:** spatie/laravel-permission (roles/permissions tables); a custom permissions table; role column + policies.
- **Why this one:** The spec has three fixed roles with ownership-based rules (customer owns the ticket, agent is the assignee). Ownership rules do not fit a flat permission list and need policies regardless; a permission package would add tables and a permission cache for little gain.
- **Trade-off:** A fourth role means editing policies. Upgrade path: introduce spatie/laravel-permission for the role-only checks (`isStaff()`, `isAdmin()`), keep the ownership logic in policies.

### D-033 Policies for per-record rules; `role` middleware only for coarse groups
- **Decision:** `EnsureRole` (alias `role`, e.g. `role:admin,agent`) guards the staff area (`/staff/*`) and admin area (`/admin/*`). Every controller action still calls `$this->authorize()` against a policy or gate. The base `Controller` uses `AuthorizesRequests`.
- **Options considered:** Middleware only; policies only; both.
- **Why this one:** Middleware knows only the role, not the ticket; policies receive the model, so ownership and state rules are enforced uniformly. The middleware is a cheap outer fence that keeps whole areas closed even if an action forgets a check.
- **Trade-off:** Two places mention roles. `EnsureRole` throws `AuthenticationException` for guests and `ValueError` on a role typo in a route definition, so misconfiguration fails loudly.

### D-034 Scoped queries in addition to policies
- **Decision:** Lists use `Ticket::visibleTo($user)`; single records use `TicketPolicy::view`.
- **Options considered:** Policy filtering of collections after loading; scope only; scope + policy.
- **Why this one:** Policies protect one record; scopes protect lists, searches, dashboards and exports at the SQL level (and with correct pagination counts). Both are needed so a customer never sees another customer's data.
- **Trade-off:** Two rules must stay in sync; the HTTP tests cover both paths.

### D-035 404 instead of 403 for customers touching other customers' tickets
- **Decision:** `TicketPolicy::before()` returns `Response::denyAsNotFound()` for every ability when a customer is not the ticket's creator. `AttachmentPolicy` denies as not found too. Staff denials stay 403.
- **Options considered:** 403 everywhere; a `visibleTo`-scoped route binding; `denyAsNotFound` in the policy.
- **Why this one:** Ticket numbers are sequential (`TKT-2026-000123`), so a 403 would confirm that a number exists and enable enumeration. Doing it in the policy covers every ability (view, reply, close, ...), not just the GET route, and keeps one source of truth. An unknown ticket number and someone else's ticket produce the same 404.
- **Trade-off:** A customer who mistypes a number cannot tell "missing" from "not yours" (intended).

### D-036 Internal notes: who may add and who may see
- **Decision:** `addInternalNote` = admin or the assigned agent. `viewInternalNotes` = any staff, and it is the ONLY switch for note visibility (replies list, note attachments, activity timeline).
- **Options considered:** Any agent may add notes; only assignee/admin.
- **Why this one:** The default the brief suggested. Notes stay attributable to the people working the ticket; other agents still read them for context.
- **Trade-off:** A helping agent must be assigned (or ask the assignee) to leave a note.

### D-037 What policies check vs. what Actions check
- **Decision:** Policies check who may act, plus state preconditions that decide whether the action exists for this ticket at all: customer `update` only while Open; `accept` only unassigned and active; `assign`/`reassign`/`release` only active (and assigned, for reassign/release); customer `close`/`reopen` only when Resolved; staff `reopen` only when Resolved/Closed. Staff `resolve`/`close` are role-only; Actions re-validate every status change with `TicketStatus::canTransitionTo()` and return a friendly validation message.
- **Options considered:** All state logic in policies; none in policies.
- **Why this one:** Buttons hidden by `@can` match what the server allows, while illegal transitions get a 422-style message instead of a bare 403.
- **Trade-off:** State rules appear in two layers; the enum is the single source for transitions.

### D-038 Field-level edit rights through separate abilities
- **Decision:** `update` covers subject/description; `changePriority` and `changeCategory` (admin or assignee) gate those fields. The update FormRequest will accept priority/category only when the user has those abilities.
- **Options considered:** One `update` ability with role checks inside the FormRequest; separate abilities.
- **Why this one:** The policy remains the only place that knows the rule; FormRequests and views ask the same question.
- **Trade-off:** More ability names.

### D-039 Ticket URLs use the ticket number; attachments use scoped bindings
> Update (Prompt 7): the attachment route is now `GET /attachments/{attachment}/download` (D-076); files live under `tickets/{id}/{Y}/{m}/` and the full path is stored in `stored_name`.
- **Decision:** `Ticket::getRouteKeyName()` = `ticket_number`. Downloads go through `/tickets/{ticket}/attachments/{attachment}` with `->scopeBindings()`, streamed by `AttachmentController` from the private disk at `attachments/{ticket_id}/{stored_name}`, with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. A missing file logs a warning with context and returns a friendly 404.
- **Options considered:** Numeric ids in URLs; `/attachments/{id}` without the ticket; public URLs.
- **Why this one:** Human-readable URLs; the scoped binding guarantees the attachment belongs to the ticket in the URL; the policy then decides access. Files never get a public URL.
- **Trade-off:** Downloads pass through PHP.

### D-040 Blade helpers are cosmetic
- **Decision:** `@staff ... @endstaff` (`Blade::if`) and `@can(...)` hide staff UI and unavailable actions. No view check is relied on for security.
- **Options considered:** Hiding UI only through policies passed to views; no UI hiding at all.
- **Why this one:** Clear templates; every request is still authorized server-side.
- **Trade-off:** None beyond keeping UI and policy names aligned.

### D-041 Landing pages and the first role-gated screens
- **Decision:** Breeze's `/dashboard` became a redirect (staff → `/staff/dashboard`, customers → `/tickets`). Added read-only screens so the authorization layers are exercised end to end: ticket list and detail (internal notes and activity for staff only), staff dashboard (five indexed counts + the most overdue tickets), admin SLA settings overview (editing comes with the SLA prompt). Import routes will join the `role:admin` group.
- **Options considered:** Keep Breeze's static dashboard; build stubs only; minimal real screens.
- **Why this one:** The 404 and internal-note rules can be tested over HTTP, not only at the policy level.
- **Trade-off:** These screens will be extended by later prompts.

### D-042 Breeze auth and profile controllers are exempt from policies
- **Decision:** Login, registration, password and profile controllers keep Breeze's behaviour without `authorize()` calls.
- **Options considered:** Wrapping Breeze controllers in policies (nothing to authorize against: they act on the signed-in user).
- **Why this one:** They act only on the authenticated user (or a guest), so there is no other record to protect; their access is enforced by `auth`/`guest` middleware, `current_password` and signed URLs.
- **Trade-off:** The "every controller calls authorize()" rule applies to application controllers, not the scaffolded auth ones.

### D-051 Route binding scoped by `visibleTo()`
- **Decision:** `Route::bind('ticket', ...)` resolves `{ticket}` by `ticket_number` through `Ticket::visibleTo($user)`. TicketPolicy's `denyAsNotFound` stays as the second layer.
- **Options considered:** Policy only (D-035); scoped binding only; both.
- **Why this one:** The foreign ticket is never loaded at all, and every ticket route (show, edit, update, resolve, close, reopen, status) returns the same 404 as an unknown number.
- **Trade-off:** The binding depends on the authenticated user (routes are always behind `auth`).

### D-120 Email verification is actually enforced
- **Decision:** `User` implements `MustVerifyEmail`. Breeze ships that line commented out, so the `verified` middleware on every route was letting unverified users through; Larastan exposed this. A completed password reset also sets `email_verified_at`, because the reset link reached the mailbox. Imported customers, who first sign in through a reset link, therefore do not have to verify twice.
- **Options considered:** Drop `verified` from the routes; keep verification as a separate step after a reset.
- **Why this one:** The routes already expressed the intent. Proving mailbox control twice in a row adds friction and no security.
- **Trade-off:** New self-registered customers must click the verification email before they can open a ticket.

### D-122 A route-authorization audit test instead of relying on discipline
- **Decision:** `tests/Feature/Security/RouteAuthorizationAuditTest.php` walks the route table on every run. Each authenticated route must satisfy one of three conditions, or the build fails:
  - it has `role:*` middleware;
  - its controller action contains a detectable authorization call (`$this->authorize`, `Gate::authorize|allows|denies`, `->can(`, or a FormRequest whose `authorize()` is more than `return true`);
  - it is on a reviewed `SELF_SERVICE` allowlist (Breeze routes that act only on the signed-in user, each with a reason).

  Guest routes must be on a reviewed `GUEST` list. A second assertion requires a policy call in every `tickets.*`, `admin.*` and download action even when role middleware is present. A third fails on allowlist entries whose route no longer exists.
- **Options considered:** Code review only; a custom middleware that forces authorization on every request (Laravel has no reliable "was authorized" signal); a static-analysis rule.
- **Why this one:** It turns "authorization must be enforced on the server side" into an automated guarantee. A new route without authorization cannot be merged without someone consciously adding it to a reviewed list with a reason.
- **Trade-off:** Detection is textual; an action could call `authorize()` on the wrong ability and still pass. The policy matrix and IDOR tests cover correctness, while the audit covers presence.

### D-123 Per-user rate limits on write and file endpoints
- **Decision:** Named limiters (`AppServiceProvider::configureRateLimits`), keyed by user id (IP for guests) and configured in `support.rate_limits`. Defaults: ticket creation 10/min, replies 30/min, attachment downloads 60/min, import uploads 5/min. Breeze's login throttle (5 attempts per email+IP) and the verification throttle stay. Exceeding a limit returns 429.
- **Options considered:** One global `throttle:60,1` on the web group; IP-based limits only.
- **Why this one:** The limits match how each action is abused (ticket spam, download scraping, import floods) without slowing normal page views. Per-user keys do not punish a whole office behind one NAT.
- **Trade-off:** The limiter uses the cache store, so production needs a shared cache (Redis) for limits to hold across servers.

### D-124 Security headers with a nonce-based CSP
- **Decision:** `SecurityHeaders` middleware on the web group sends:
  - `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`;
  - HSTS over HTTPS;
  - a CSP: `default-src 'self'`, scripts from self plus a per-request nonce (`Vite::useCspNonce()`; the two inline Alpine helpers carry it), `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`.

  Responses that set their own CSP keep it (attachment downloads use `default-src 'none'; sandbox`). The Vite dev server origin is allowed only while `public/hot` exists.
- **Options considered:** No CSP; a package such as spatie/laravel-csp; `'unsafe-inline'` scripts.
- **Why this one:** A nonce blocks injected inline script even if an escaping bug slipped through, with about 60 lines of code and no dependency.
- **Trade-off:** The standard Alpine build needs `'unsafe-eval'`, and inline styles are allowed. Both are listed in KNOWN_LIMITATIONS with the fix (`@alpinejs/csp`).

### D-125 Password policy and cookie defaults
- **Decision:** `Password::defaults()` requires at least 10 characters with mixed case and numbers in production, and rejects passwords found in the Have I Been Pwned breach corpus (k-anonymity, only a hash prefix leaves the server); elsewhere the minimum is 8. Session cookies default to `secure` when `APP_ENV=production` (overridable through `SESSION_SECURE_COOKIE`), with `http_only` and `same_site=lax`.
- **Options considered:** Breeze defaults (8 characters, no breach check); always-strict rules, which slow down development and tests.
- **Why this one:** Strong rules where real accounts live, and a fast local loop.
- **Trade-off:** The breach check calls an external API during registration and password changes in production. Laravel allows the password when the API is unreachable.

## Tickets and lifecycle

### D-044 Ticket numbers from a locked counter row
- **Decision:** `TicketNumberGenerator::generate()` runs in one transaction: `insertOrIgnore` the year's row, `SELECT ... FOR UPDATE`, add 1, format `TKT-YYYY-NNNNNN`. `reserve($count)` allocates a contiguous block under one lock (CSV import). Padding is a minimum width, so 1,000,000 becomes `TKT-2026-1000000` (fits VARCHAR(20)). The UNIQUE index on `ticket_number` stays as the final safety net.
- **Options considered:** (a) derive from the auto-increment id; (b) `MAX(ticket_number)+1`; (c) Redis `INCR`; (d) UUID/random; (e) counter table with row lock.
- **Why this one:** (a) cannot reset yearly and leaks gaps; (b) is a race condition (two requests read the same MAX) and scans the table; (c) loses numbers if Redis is flushed and is not transactional with the DB; (d) is not human-friendly. A locked counter row is correct, gapless on rollback and cheap, because the lock is held only for one single-row UPDATE.
- **Trade-off:** Ticket creation serialises on one row per year, which is fine for support-desk volumes; at extreme scale, pre-allocate blocks with `reserve()`. The year comes from the UTC timestamp (`now()->year`), so tickets created between 00:00 and 06:00 Asia/Dhaka on 1 January still carry the previous year.

### D-045 Number generated inside the CreateTicket transaction (gapless)
- **Decision:** `CreateTicket` calls the generator inside its own `DB::transaction`. The generator's transaction nests as a savepoint on the same connection.
- **Options considered:** Generate in a separate committed transaction (numbers survive failures, leaving gaps); generate inside the ticket transaction.
- **Why this one:** If the ticket insert, SLA lookup or activity insert fails, the counter increment rolls back with it, so no number is burned silently. Tests prove both a direct rollback and a failed `CreateTicket` leave the next number at 000001.
- **Trade-off:** The counter row stays locked until the whole creation transaction commits (a few milliseconds), slightly longer than a standalone allocation.

### D-046 Status transition map in the enum (replaces D-029)
- **Decision:** `TicketStatus::allowedTransitions()`: open → in_progress, waiting_for_customer, resolved; in_progress → waiting_for_customer, resolved, open; waiting_for_customer → in_progress, resolved, closed; resolved → closed, in_progress; closed → none. `adminOnlyTransitions()`: closed → in_progress. `canTransitionTo($to, $byAdmin)` is the single check, used by `ChangeTicketStatus` and by the views to hide impossible buttons. Illegal moves throw `InvalidTicketTransition`, which renders as a validation error on `status` (redirect back, or 422 JSON) and is logged at info level.
- **Options considered:** Ad-hoc `if` statements in controllers/actions; a state-machine package; the map in the enum.
- **Why this one:** One source of truth, trivially unit tested (every from/to pair is checked by a dataset test).
- **Trade-off:** Adding a status means updating the map and its test.

### D-029 Status transitions allow reopening — SUPERSEDED by D-046
- **Decision:** `Open → InProgress | WaitingForCustomer | Resolved | Closed`; `InProgress → WaitingForCustomer | Resolved | Closed`; `WaitingForCustomer → InProgress | Resolved | Closed`; `Resolved → Closed | Open`; `Closed → Open`.
- **Options considered:** Closed as terminal; reopening allowed.
- **Why this one:** Customers often reply to a resolved or closed ticket; reopening keeps one thread. The simplest reasonable reading of the brief.
- **Trade-off:** A reopened ticket needs a fresh SLA decision (handled by the transition Action).

### D-047 Customers may choose any priority
- **Decision:** The create form offers all priorities to everyone. After creation only an admin or the assigned agent may change priority (`changePriority`); every change is recorded as a `priority_changed` activity and recalculates the SLA.
- **Options considered:** Cap customers at `high`; let customers pick any priority and let staff triage.
- **Why this one:** The default in the brief. Customers know their own urgency; staff correct it, and the audit trail shows who changed it.
- **Trade-off:** Customers can over-prioritise; triage is a staff task.

### D-048 Events after commit
- **Decision:** `TicketCreated`, `TicketUpdated`, `TicketStatusChanged`, `TicketResolved` and `TicketAssigned` implement `ShouldDispatchAfterCommit`; `AutoAssignTicket` is dispatched with `->afterCommit()`.
- **Options considered:** Dispatch immediately; dispatch from the controller after the Action returns; after-commit events.
- **Why this one:** Listeners (emails, notifications) must never fire for a transaction that later rolls back, including when an Action is nested inside a larger transaction. A test dispatches inside a rolled-back outer transaction and asserts nothing fired.
- **Trade-off:** Listeners cannot veto the change; they react to committed facts only.

### D-049 Reopen restarts the SLA; priority change recalculates from creation
- **Decision:** Leaving a finished status (resolved/closed → active) clears `resolved_at`, `closed_at`, escalation and notification markers, and computes a fresh target from the reopen time. A priority change recalculates from `created_at` (time already spent still counts).
- **Options considered:** Keep the original deadline on reopen (instantly breached); restart from reopen time.
- **Why this one:** A reopened ticket is new work with a fair target, while a re-prioritised ticket should not gain extra time.
- **Trade-off:** A ticket that is reopened and then re-prioritised falls back to a target counted from creation. Prompt 5 (SLA) can add an explicit SLA start/pause record if this needs refining.

### D-052 Status endpoints and abilities
- **Decision:** Dedicated `POST resolve|close|reopen` routes plus a generic `POST status` route. The generic route authorizes by target: resolved → `resolve`, closed → `close`, leaving a finished state → `reopen`, otherwise the new `changeStatus` ability (admin or assignee). `TicketPolicy::reopen` now follows the new map: resolved tickets can be reopened by the owner, the assignee or an admin; closed tickets by an admin only.
- **Options considered:** One generic status endpoint only; one endpoint per transition only.
- **Why this one:** One generic endpoint for working-status moves without letting it bypass the stricter abilities.
- **Trade-off:** One more ability name.

### D-053 Friendly handling of database failures
- **Decision:** `bootstrap/app.php` renders a `QueryException` on non-GET web requests as a redirect back with input and a `general` error ("Something went wrong while saving..."). The exception is still reported to the log, with route name and URL added to the context.
- **Options considered:** try/catch in each controller; a global render callback.
- **Why this one:** No exception is swallowed, the log keeps full context, and users never see a 500 page for a failed save. GET requests and JSON clients keep the default handling.
- **Trade-off:** A save that fails for a non-transient reason shows a generic message; the log carries the details.

### D-054 Ticket list tabs and ordering
- **Decision:** `?scope=active` (default) or `?scope=closed` (resolved and closed), validated by `ListTicketsRequest`; both are ordered newest first.
- **Options considered:** Order the active tab by due date; newest first.
- **Why this one:** `created_at` ordering is served by `tickets_created_at_index` (staff) and `tickets_created_by_created_at_index` (customers) without a filesort across several statuses. Overdue work is surfaced by the SLA badge and the staff dashboard.
- **Trade-off:** The most urgent ticket is not always at the top of the list.

## Assignment and concurrency

### D-050 Interfaces for the SLA calculator and agent selection, with interim implementations
> Update (Prompt 5): `WallClockSlaCalculator` was removed; `SlaCalculator` is now bound to `BusinessHoursSlaCalculator` (D-057).
> Update (Prompt 6): `AgentSelector` / `NullAgentSelector` were replaced by `AssignmentStrategy` (D-069), and `AutoAssignTicket` now calls `ClaimTicket` (D-066).
- **Decision:** `SlaCalculator` (bound to `WallClockSlaCalculator`: active policy minutes, calendar time) and `AgentSelector` (bound to `NullAgentSelector`: never picks anyone). `CreateTicket`, `UpdateTicket` and `ChangeTicketStatus` depend only on the interface. `AutoAssignTicket` (queued, 3 tries, after commit) calls the selector and then `AssignTicket`, which re-checks under a row lock with `onlyIfUnassigned`, so a late job never overrides a manual assignment. `config('support.assignment.auto_on_create')` (default true) switches the job on.
- **Options considered:** Implement business-hours SLA and least-workload now; stub behind interfaces.
- **Why this one:** Later prompts replace one binding each without touching callers; the job, the action and the locking are already tested with a fake selector.
- **Trade-off:** Until the real selector lands, auto-assignment logs "no agent available" and tickets wait for agents to accept them.

### D-066 Conditional single-statement UPDATE (compare-and-set) for claiming
- **Decision:** `ClaimTicket` takes an unassigned ticket with one statement: `UPDATE tickets SET assigned_agent_id = ?, assigned_at = ?, status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END, updated_at = ? WHERE tickets.id = ? AND assigned_agent_id IS NULL AND status IN (?, ?, ?)`. InnoDB row-locks the row for the statement; a concurrent second UPDATE waits, re-evaluates the WHERE against the committed row and matches zero rows. Self-accept, auto-assignment and (through the same guard shape) admin assignment and release all use this pattern.
- **Options considered:** (a) `SELECT ... FOR UPDATE` then `UPDATE`; (b) an optimistic `version` column; (c) a Redis/cache lock; (d) a conditional UPDATE.
- **Why this one:** One atomic statement: no window between check and write, no lock held across PHP code, no deadlock risk, no extra column, and no dependency on Redis. (a) is correct but holds a row lock while PHP runs and can deadlock when two code paths lock rows in different orders; (b) needs retries and a version bump on every write path; (c) adds a second system that can fail separately from the database, and a lock that expires or is released too early is a lost update.
- **Trade-off:** The previous status must be read in a separate (plain) SELECT for the audit entry. The CAS itself never depends on it; in a race that also changed the status, the logged "old status" could be stale.

### D-067 Affected rows as the winner signal; losers get 409
- **Decision:** `ClaimTicket` returns `true` when the UPDATE affected 1 row, `false` for 0. The accept endpoint turns `false` into `AssignmentConflict` → **409 Conflict**: JSON `{"message": "This ticket was just taken by another agent."}`, or an HTML page in the app layout with the same message and links back to the queue / ticket. Admin (re)assignment and release throw the same exception when their guarded UPDATE matches nothing. A second accept by the agent who already won (double click) counts as success.
- **Options considered:** Re-reading the row after the write; exceptions from the database; affected-row count.
- **Why this one:** The affected-row count is the database's own answer to "did my write apply"; no second read can race with it.
- **Trade-off:** HTML clients get a 409 page rather than a redirect with a flash message, because a redirect cannot carry a 409 status; the page shows the message prominently.

### D-068 Guarded reassignment and release
- **Decision:** Admin assignment posts `expected_agent_id` (the assignee rendered on the page). `AssignTicket` updates `WHERE id = ? AND assigned_agent_id <=> :expected` (written portably as `IS NULL` / `= ?`) and logs `assigned` or `reassigned` with old and new agent. `ReleaseTicket` updates `WHERE assigned_agent_id = :actor` (an admin uses the assignee they saw) and sets status back to `open`. Assignment of an `open` ticket moves it to `in_progress` inside the same statement.
- **Options considered:** Last write wins; pessimistic lock held while the admin is on the page.
- **Why this one:** Two admins reassigning at once, or an admin reassigning while the agent releases, can never silently overwrite each other; the loser gets the conflict message.
- **Trade-off:** An admin must reload after a conflict to see the current assignee.

### D-069 Strategy pattern, least-workload by default
- *(Amended by D-138: the strategy switch and the round-robin strategy were removed; `LeastWorkloadStrategy` is used directly.)*
- **Decision:** `AssignmentStrategy::pick(Ticket): ?User`, resolved from the container by name (`config('support.assignment.strategy')`, map in `support.assignment.strategies`). `LeastWorkloadStrategy` (default) and `RoundRobinStrategy`. Both draw from `CandidateAgents`: role agent AND available AND (specialised in the ticket's category OR no categories at all = generalist), falling back to every available agent when that set is empty. Least-workload orders by weighted active workload (critical 3, high 2, medium/low 1, from `support.assignment.workload_weights`), then `last_assigned_at` (never-assigned first), then id. The workload is one correlated subquery for all candidates (3 queries total, tested), served by `tickets(assigned_agent_id, status)`.
- **Options considered:** Pure round-robin; least-workload; skills scoring.
- **Why this one:** Round-robin ignores that one agent may be buried in critical tickets; least-workload plus category match plus availability covers the spec's criteria. Round-robin is kept to show the abstraction works (switching is one config value).
- **Trade-off:** A specialist is not preferred over an equally eligible generalist; both are candidates and workload decides. Agents specialised only in other categories are excluded unless nobody else is eligible.

### D-070 Pessimistic row lock on candidate agents for auto-assignment only
- **Decision:** Inside the job's transaction, `CandidateAgents::lock()` runs `SELECT id FROM users WHERE id IN (...) AND role = 'agent' AND is_available = 1 ORDER BY id FOR UPDATE` before the workload query; `ClaimTicket` then runs in the same transaction, and the locks are released at commit.
- **Options considered:** No lock (two jobs for different tickets read the same counts and both pick the least-loaded agent); a global mutex; row locks on the candidate pool.
- **Why this one:** Auto-assignment is a genuine read-then-decide across many rows, which a compare-and-set cannot express ("pick the least-loaded agent"). Locking in id order prevents deadlocks between overlapping pools. This serialises auto-assignment per candidate pool, which is acceptable because the lock lasts for three short queries. Using each technique where it fits (CAS for claiming one row, a short pessimistic lock for the pool decision) is the point.
- **Trade-off:** Under a burst of new tickets, jobs for the same pool queue on the lock (milliseconds each).

### D-071 Accept policy no longer checks "unassigned"
- **Decision:** `TicketPolicy::accept` = any agent on an active ticket. Whether the ticket is still unassigned is answered by `ClaimTicket`; views add `assigned_agent_id === null` to show the button. The policy matrix was updated (assigned active tickets: agents now "allowed to try").
- **Options considered:** Keep "unassigned" in the policy (racy, and the loser would get 403 instead of 409).
- **Why this one:** Otherwise the losing agent of a sequential race would get 403 Forbidden from the policy instead of the required 409 Conflict, and the policy would duplicate (racily) what the database decides atomically.
- **Trade-off:** The policy alone no longer tells whether the button should be shown.

### D-072 Unique auto-assign job, skip record, five-minute retry
- **Decision:** `AutoAssignTicket` implements `ShouldBeUnique` keyed by ticket id (`uniqueFor` 600 s as a safety net), tries 3, backoff [5, 30, 120]. If no agent is available, the ticket stays unassigned and an `auto_assign_skipped` activity is written, but only when it is not already the latest activity, so the five-minute retries do not flood the timeline. `tickets:assign-unassigned` re-queues up to `support.assignment.retry_batch_size` (100) unassigned active tickets, oldest `due_at` first; it is scheduled `everyFiveMinutes()->withoutOverlapping()->onOneServer()`. If a person accepts the ticket while the job runs, `ClaimTicket` returns false and the job ends: the ticket is assigned, which is the goal.
- **Options considered:** No uniqueness (the create hook and a scheduler retry can queue the same ticket twice); unique jobs.
- **Why this one:** Prevents duplicate dispatch from the create hook and the scheduler racing each other. Even without it, the CAS would keep the result correct; uniqueness avoids wasted work and duplicate log lines.
- **Trade-off:** Needs a lock-capable cache store (database or Redis).

### D-073 Agent availability
- **Decision:** Agents toggle `is_available` on their profile (`PATCH /profile/availability`, gate `update-availability`, Action `SetAgentAvailability`). Unavailable agents are never auto-assigned and cannot be chosen by an admin; they keep their tickets and can still accept from the queue.
- **Options considered:** Availability managed by admins only; unavailable agents lose their tickets.
- **Why this one:** Availability controls incoming automatic work; it should not strand tickets already in progress.
- **Trade-off:** An unavailable agent's open tickets are not redistributed automatically (an admin can reassign).

### D-074 Activity names and the multi-process test
- **Decision:** Activity types follow the existing naming without a `ticket_` prefix (D-056): `assigned`, `reassigned`, `unassigned`, `auto_assign_skipped`. The real race test (`tests/Concurrency`) starts 20 PHP processes against the MySQL test database, which all fire `ClaimTicket` at the same instant, and asserts exactly one `WON`. It commits real rows (no RefreshDatabase), cleans up in `finally`, and is skipped on SQLite. `pcntl_fork` is unavailable on Windows, so separate processes are used.
- **Options considered:** Only the deterministic test; a `ticket_`-prefixed naming scheme.
- **Why this one:** The deterministic test proves the guard; the process race proves the real database behaviour under true concurrency.
- **Trade-off:** About 10 seconds of test time; run alone with `php artisan test --group=concurrency`.

## SLA and escalation

### D-057 Business minutes accumulated day by day
- **Decision:** `BusinessCalendar::addBusinessMinutes()` snaps the start to the next opening, consumes what is left of each working window ([opens, closes)), and carries the remainder to the next opening. `BusinessHoursSlaCalculator` sets `dueAt = add(minutes)` and `warningAt = add(round(minutes x warning_threshold))`, returning UTC instants; all intermediate maths runs in `config('support.timezone')`.
- **Options considered:** Clock-time addition (`created_at + minutes`); business minutes day by day.
- **Why this one:** Required by the spec, and the worked example proves clock-time addition wrong: a critical ticket (120 min) created Thursday 17:00 has 60 minutes left on Thursday, Friday and Saturday are closed, so it is due Sunday 10:00, not Thursday 19:00. Unit tests pin this and the other edge cases (created on the weekend, before opening, exactly at closing, a holiday inside a 48-hour SLA, a deadline that lands exactly on 18:00 stays on that day).
- **Trade-off:** A loop per working day instead of one addition (at most a few dozen iterations for any allowed target).

### D-058 Iterative loop with a guard instead of a business-hours package
- **Decision:** Plain loops in `BusinessCalendar`, each capped at 366 iterations (`MAX_DAYS`); exceeding the cap throws `NoBusinessHoursConfigured`. Admin targets are capped at 20,160 minutes so valid configurations stay far below the guard. Time is counted in seconds so a start of 17:00:30 stays exact.
- **Options considered:** `cmixin/business-time` / `diffInBusinessHours` packages; recursion; an iterative loop.
- **Why this one:** No dependency, every line is explainable, and a misconfiguration (no working day, a year of holidays) fails loudly instead of looping forever or overflowing the stack.
- **Trade-off:** Our own code to maintain (about 60 lines, heavily tested).

### D-059 Rules in tables, config as seed defaults, one cached reader
- **Decision:** `sla_policies`, `business_hours` and `holidays` hold the rules; `config/support.php` seeds them and acts as the fallback when a table is empty (no active policy for a priority, or no `business_hours` rows at all). `SlaPolicyRepository` is the only reader outside the admin screen: it loads all three once, caches them as plain arrays (`Cache::remember`, 1 hour) and is bound as a scoped singleton (memoised per request/job, never across jobs). `SlaRulesObserver` (after commit) flushes the cache on any save/delete of the three models; the admin Actions save models individually so the observer always runs; `DatabaseSeeder` flushes explicitly because seeding mutes model events.
- **Options considered:** Hard-coded constants; config only; tables read on every calculation; tables + cache.
- **Why this one:** Admins change rules at runtime without a deploy; the rest of the app only talks to `SlaCalculator`, so a rule change touches one place; the calculation costs no queries on a warm cache (tested).
- **Trade-off:** If some but not all weekdays have `business_hours` rows, missing weekdays count as closed. Holidays are loaded in full (a small table); a very large holiday history would need a date window.

### D-060 Snapshot minutes and precomputed due_at / sla_warning_at
- **Decision:** Tickets keep `sla_policy_id`, `sla_minutes`, `sla_warning_at` and `due_at` from the moment they were calculated (see D-017). Changing the rules affects new tickets only; `php artisan sla:recalculate --open` re-applies the current rules to active tickets (from their original `created_at`), `--dry-run` reports without saving. Resolved and closed tickets are never recalculated, so met/missed history is stable. `--open` is a required opt-in because the command moves live deadlines.
- **Options considered:** Recompute SLA on every request; snapshot + optional re-apply.
- **Why this one:** Filtering, dashboards and the scheduler use indexed `WHERE due_at < now` comparisons; business-hours maths runs once per ticket, not per request.
- **Trade-off:** After a rule change, old tickets keep the old rule until an admin runs the command. A reopened ticket (D-049) that is recalculated by the command falls back to a target counted from `created_at`.

### D-061 Warning threshold as a fraction of the SLA
- **Decision:** `warning_threshold` (default 0.80) per policy; `warningAt = round(minutes x threshold)` business minutes after the start.
- **Options considered:** A fixed window ("30 minutes before due"); a fraction.
- **Why this one:** Scales with priority: 30 minutes before a 2-hour SLA is 25% of it, before a 48-hour SLA it is about 1%. A fraction warns equally early for every priority.
- **Trade-off:** Very long SLAs warn many hours ahead (e.g. low: 38.4 business hours in).

### D-062 SlaStatus enum and one resolver for PHP and SQL
- **Decision:** `SlaStatus` = within_sla, approaching, breached (active tickets), met, missed (resolved/closed, judged by `COALESCE(resolved_at, closed_at) <= due_at`). `SlaStatusResolver::resolve()` (one ticket, comparisons only) and `SlaStatusResolver::applyScope()` (SQL on the indexed columns) live side by side; `Ticket::slaStatus()`, `scopeSlaStatus()`, `slaBreached()`, `slaApproaching()` and `withinSla()` all delegate to it. A feature test builds tickets on every boundary (due == now, warning == now, no warning, finished via closed_at only) and asserts that the scope and the PHP method return the same set for every status. Boundary rules: due == now is approaching, not breached; finishing exactly at the deadline is met.
- **Options considered:** Separate PHP accessor and scopes (drift risk); computed SQL column; resolver + scope side by side with an agreement test.
- **Why this one:** One place to change the rules, and drift between list filters and badges is caught by a test.
- **Trade-off:** `LIST_COLUMNS` now includes `resolved_at` and `closed_at` so list badges can show met/missed.

### D-063 Priority change and reopen use one recalculator
- **Decision:** `TicketSlaRecalculator` (Services/Sla) re-applies the rules to a locked ticket, records `sla_recalculated` with old and new `due_at` / `sla_warning_at` / `sla_minutes`, and clears `warning_notified_at` / `breached_notified_at` only when the new deadline is in the future. A priority change counts from the ORIGINAL `created_at`; a reopen counts from the reopen time (D-049); the command counts from `created_at`. Nothing is written when the result equals the stored snapshot.
- **Options considered:** Duplicate logic in each Action; one service.
- **Why this one:** Same audit trail and notification rules everywhere the deadline can move.
- **Trade-off:** A ticket gets two activity rows for a priority change (`priority_changed` + `sla_recalculated`).

### D-064 Admin SLA settings screen
- **Decision:** `/admin/sla-settings` (role:admin + `manage-sla-settings`) edits resolution minutes (1–20,160), warning threshold (0.05–0.99, two decimals) and active flag per priority; working day, opening and closing time per weekday (closing after opening, at least one working day); and holidays (unique date). Each save is an Action in `app/Modules/Sla/Actions`, runs in a transaction, logs who changed what, and flushes the cache through the observer. This screen reads the rule tables directly because it owns them.
- **Options considered:** Config-only rules (needs a deploy to change); a generic settings key/value table.
- **Why this one:** The smallest editor that covers every rule the calculator uses.
- **Trade-off:** No per-row history of rule changes beyond the log.

### D-065 Deliberately not implemented (possible extensions)
- **Pausing the SLA clock** while a ticket is `waiting_for_customer`: needs a pause log (or `sla_paused_at` + accumulated pause minutes) and recalculation on resume.
- **Per-customer or per-category SLAs**: would add a scope column to `sla_policies` and a lookup order in `SlaPolicyRepository::policyFor()`.
- **Multiple business timezones** (per team or customer): the calendar already takes the timezone as a parameter; the repository would build one calendar per zone.
- **Partial-day holidays** (e.g. closing at 13:00): `holidays` would need optional `opens_at` / `closes_at` overriding the weekday window.
- **An explicit SLA start time** (`sla_started_at`), so reopen and later recalculations share one starting point (see D-049 / D-060).
- **Why not now:** The spec says not every enterprise scenario is needed; each extension fits behind the existing `SlaRules` / `SlaCalculator` interfaces.
- **Options considered:** Implementing every extension now.
- **Trade-off:** The listed scenarios need code changes when they become requirements.

### D-091 A stateless scheduled scan instead of one delayed job per ticket
- **Decision:** `sla:scan` runs every minute and recomputes from the database which tickets reached their warning point, breached, or need level-2 escalation; it queues one `EscalateTicketJob(ticketId, kind)` per ticket.
- **Options considered:** Dispatch a delayed job per ticket at `sla_warning_at` / `due_at`; a scheduled scan.
- **Why this one:** Delayed jobs cannot be rescheduled when the priority, business hours or holidays change, and lost Redis data would lose every pending escalation. A scan is stateless: every run recomputes the truth from the database.
- **Trade-off:** Up to one minute of delay, acceptable for hour-scale SLAs.

### D-092 Range queries on precomputed columns
- **Decision:** The passes filter on stored columns only: warning = active, `warning_notified_at` NULL, `sla_warning_at <= now`, `due_at >= now` → index `(status, sla_warning_at)`; breach = active, `breached_notified_at` NULL, `due_at < now` → `(status, due_at)`; level 2 = active, `is_escalated`, `escalation_level = 1`, `due_at <= now − level2_after_minutes` → `(status, is_escalated, due_at)`. Warning and breach reuse `Ticket::slaApproaching()` / `slaBreached()`, i.e. the SlaStatusResolver rules (D-062). That means `due_at < now` for a breach where the brief wrote `<=`: at the exact due second a ticket counts as approaching, one second later as breached, consistently with the badges.
- **Options considered:** Compute business time for every active ticket in PHP on each run; precomputed columns.
- **Why this one:** The scan is a cheap indexed range query, not a PHP loop over the calendar algorithm.
- **Trade-off:** The precomputed columns must be kept current (done by the SLA recalculator, D-063).

### D-093 Guarded UPDATE as the idempotency lock
- **Decision:** `EscalateTicket` applies each step with a compare-and-set UPDATE and only writes activities and dispatches events when exactly one row changed. Warning: `SET warning_notified_at WHERE id AND warning_notified_at IS NULL AND active AND sla_warning_at <= now`. Breach: `SET breached_notified_at, is_escalated = 1, escalation_level = max(level, 1) WHERE id AND breached_notified_at IS NULL AND active AND due_at < now` → `sla_breached` + `escalated` activities, `SlaBreached` (assignee + all admins), and an immediate `AutoAssignTicket` if nobody owns the ticket. Level 2: `SET escalation_level = 2 WHERE id AND escalation_level = 1 AND is_escalated AND active AND due_at <= now − N` → `escalated` activity, `TicketEscalated` → every admin. The job is also `ShouldBeUnique` per ticket + kind (tries 3), which saves duplicate queue work; the guarded UPDATE is the real guarantee.
- **Options considered:** A cache lock; check-then-update; a guarded UPDATE.
- **Why this one:** The same compare-and-set principle as ticket assignment (D-066): overlapping scheduler runs, several workers and job retries are all safe, and the WHERE re-checks "still active and still due", so a ticket resolved between the scan and the job is never escalated (tested).
- **Trade-off:** Each step is a separate UPDATE + read; trivial at this scale.

### D-094 chunkById and one small job per ticket
- **Decision:** The scan selects only `id` and walks candidates with `chunkById(200)`; for each ticket it dispatches a job. Counts are printed with `--verbose` only, so cron output stays quiet.
- **Options considered:** `get()`; offset `chunk()`; doing the notifications inside the scan.
- **Why this one:** Constant memory, and walking by primary key does not skip rows while jobs flip the flags and the matching set shrinks (offset chunking would). The scan stays fast, and one failing ticket only fails its own job (retried 3 times) instead of stopping the run.
- **Trade-off:** More, smaller queue jobs.

### D-095 Escalation levels, re-arming and history
- **Decision:** Level 1 at the breach. Level 2 when an active ticket is still open `support.escalation.level2_after_minutes` (default 120) CLOCK minutes after `due_at`: overdue time counts around the clock, and it keeps the level-2 query a simple range on `due_at`. When a priority change or SLA recalculation moves `due_at` into the future, the warning/breach markers are cleared (D-063) so the ticket can warn and breach again; `is_escalated` / `escalation_level` stay as they are, and every escalation remains in the activity log. A reopen clears the escalation (D-049).
- **Options considered:** Business-minute level 2 (needs the calendar in SQL); re-escalating to higher levels on every re-breach.
- **Why this one:** Clock minutes keep level 2 a range query on an indexed column; re-arming after a recalculation keeps the history honest.
- **Trade-off:** A re-armed ticket that breaches again logs another `escalated` entry but stays at its current level.

### D-096 Scheduled maintenance and the single cron entry
- **Decision:** `routes/console.php` registers: `sla:scan` every minute and `tickets:assign-unassigned` every five minutes (both `withoutOverlapping(10)->onOneServer()->runInBackground()`); `tickets:auto-close-resolved` daily 02:00 (closes tickets resolved for `support.auto_close_resolved_after_days` = 3 days via `CloseTicket` with a system actor, skipping any ticket reopened meanwhile; served by a new `(status, resolved_at)` index); `attachments:purge-orphans` daily 03:30; `model:prune` for `App\Models\Notification` daily 04:00 (read notifications older than 90 days; unread ones are kept); `queue:prune-failed --hours=168` weekly. One cron entry runs everything: `* * * * * php artisan schedule:run`. To make in-app notifications prunable, `User::notifications()` now uses `App\Models\Notification` (extends `DatabaseNotification`, `Prunable`).
- **Options considered:** Cron lines per command; the Laravel scheduler.
- **Why this one:** One cron entry, guards declared next to each task. `withoutOverlapping` + `onOneServer` make it safe with several app servers (needs a lock-capable cache: database or Redis); the `10` expires a stale lock after 10 minutes if a run dies.
- **Trade-off:** `tickets:assign-unassigned` keeps a per-run limit (100, oldest due first) rather than `chunkById` over everything: AutoAssignTicket is unique per ticket, and the next run five minutes later picks up the rest.

## Conversation and attachments

### D-075 Private disk streamed through an authorising controller
- **Decision:** Files are stored on the `private` disk (`storage/app/private`, `serve => false`, never web-accessible) and read only through `GET /attachments/{attachment}/download` (auth). The controller calls `$this->authorize('download', $attachment)`: AttachmentPolicy applies the ticket's view rule and the internal-note rule, and answers 404 for both "not yours" and "does not exist". The response streams from the disk with `Content-Disposition: attachment` (inline only for PNG/JPEG when `?inline=1`), `X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox`. Storage paths never appear in HTML, JSON or headers.
- **Options considered:** Public storage with obscure URLs; temporary signed URLs; private disk + controller.
- **Why this one:** Obscurity is not authorization, and a signed URL keeps working for whoever it is forwarded to. With a controller, the same TicketPolicy that protects the ticket protects the file, including the internal-note rule, on every request.
- **Trade-off:** PHP serves the bytes. At scale, move the disk to S3 and redirect to a short-lived temporary URL generated only after the policy check (the disk name is already config: `SUPPORT_ATTACHMENTS_DISK`).

### D-076 Extension whitelist plus content-based MIME check
- **Decision:** `App\Modules\Conversation\Support\AttachmentRules` (built from `config('support.attachments')`) is shared by the ticket form and the reply form: at most 5 files; each `file`, `max:5120` KB, `extensions:` (the client's extension) and `mimes:` (the extension guessed from the file's content via finfo) both limited to pdf, png, jpg, jpeg, txt, docx, xlsx, csv; plus `SafeUpload`, which rejects empty files and any executable extension anywhere in the name (`invoice.php.pdf`). `invoice.pdf.exe` fails the extension whitelist. PHP, phtml, exe, sh, js, html, svg… are never on the whitelist. Tests upload real files whose content is PHP but whose name is `.pdf`, and they are rejected.
- **Options considered:** A blacklist of dangerous extensions; extension check only; MIME check only; both.
- **Why this one:** Blacklists always miss something; checking both the name and the content catches renamed executables.
- **Trade-off:** Legitimate but unusual types (e.g. `.zip`, `.heic`) need a config change. Text-based formats such as CSV are sniffed as `text/plain` and accepted through `txt`.

### D-077 UUID stored names in a dated per-ticket directory
- **Decision:** `StoreAttachments` writes `tickets/{ticket_id}/{Y}/{m}/{uuid}.{ext}` and stores that path in `stored_name` (unique), plus the content-sniffed MIME type, size and SHA-256 checksum. The client's name is cleaned by `SafeFilename` (basename only, no control/header/path characters, ≤150 characters, extension kept) before it is stored, and cleaned again when sent in Content-Disposition.
- **Options considered:** Original names; hashes of the content; UUIDs.
- **Why this one:** UUIDs prevent path traversal, overwrites and filename-based enumeration; the dated directories keep any one folder small.
- **Trade-off:** Two uploads of the same file are stored twice (the checksum allows de-duplication later).

### D-078 Attachments belong to a ticket and optionally a reply
- **Decision:** `attachments.ticket_id` (required FK) and `attachments.ticket_reply_id` (nullable FK), not a polymorphic table. Ticket-level files come from the create form; reply-level files from the conversation.
- **Options considered:** Polymorphic `attachable_type/attachable_id`; two FKs.
- **Why this one:** Simpler queries and real foreign keys (cascades included); one nullable column covers both cases, and the internal-note rule can follow `ticket_reply_id`.
- **Trade-off:** Attaching files to a third kind of parent would need another column.

### D-079 Reply type forced server-side; closed tickets take no public replies
- **Decision:** `StoreReplyRequest::replyType()` returns Public for every customer whatever they send, and validation rejects any `type` other than `public` from a customer (422, "Only staff can add internal notes."). The controller authorizes `addInternalNote` or `reply` from the derived type; `AddReply` refuses a customer note again (defence in depth). `TicketPolicy::reply` now denies public replies on closed tickets (the customer must reopen); `AddReply` throws `ReplyNotAllowed` as a second guard. Staff may still add internal notes to closed tickets.
- **Options considered:** Trust a hidden `type` field; separate routes per type; derive the type on the server.
- **Why this one:** Internal notes must never be visible to customers, and any client-supplied value is untrusted.
- **Trade-off:** None for customers; staff choose the type with a radio button.

### D-080 One visibility switch for the conversation and the timeline
- **Decision:** `Ticket::repliesVisibleTo($user)` (all replies for staff, `publicReplies()` for customers) and `Ticket::activitiesVisibleTo($user)` (customers only see types where `ActivityType::isVisibleToCustomer()` is true) both hinge on `TicketPolicy::viewInternalNotes`. Hidden from customers: internal notes, assignment details (assigned/reassigned/unassigned/auto_assign_skipped), SLA internals (recalculated/warning/breached) and escalation. The show page and the JSON view (`TicketResource`, when `Accept: application/json`) use the same filtered collections; the resource never reads the unfiltered relations. Customers now see their filtered timeline (previously staff-only). Activity descriptions never contain reply text. A test writes a unique marker into a note body and an attachment name, then checks the customer's HTML, JSON, timeline, notifications and mail for it.
- **Options considered:** Filtering in each view and resource separately; separate tables for internal data.
- **Why this one:** One switch, used by HTML, JSON, notifications and the timeline, cannot drift apart; the exhaustive `match` forces a visibility decision for every new type.
- **Trade-off:** Adding an ActivityType requires deciding its visibility (the `match` is exhaustive, so it cannot be forgotten).

### D-081 Conversation rules in AddReply
- **Decision:** One transaction: lock the ticket, create the reply, store the files, record `customer_replied` / `agent_replied` / `internal_note_added`, set `first_response_at` on the first public staff reply, touch the ticket, then apply status rules through `ChangeTicketStatus` (so the transition map and its activity stay the single source): a customer reply on `waiting_for_customer` → `in_progress`, or `open` when unassigned (the map now allows waiting → open); a staff public reply on `open` → `in_progress`. Internal notes never change the status. `ReplyAdded` (with the reply type) is dispatched after commit.
- **Options considered:** Logic in the controller; automatic reopen on any customer reply.
- **Why this one:** All conversation rules run under one lock and one transaction, so status, first response and activity can never disagree.
- **Trade-off:** A customer reply to a resolved ticket does not reopen it automatically; the customer uses Reopen.

### D-082 File cleanup on rollback, and an orphan sweep
- **Decision:** `StoreAttachments` deletes the files it wrote if a later file or insert fails; `AddReply` and `CreateTicket` delete the files of a transaction that rolled back (try/catch around `DB::transaction`). `attachments:purge-orphans` (daily 03:30, `--hours=24`, `--dry-run`) deletes files under `tickets/` with no `attachments` row and older than the threshold, one query per 500 paths.
- **Options considered:** Write files after commit (a failed write then leaves a row without a file); clean up on rollback plus a periodic sweep.
- **Why this one:** Rows never point at missing files, and anything the in-process cleanup misses (crash, outer transaction rolled back) is swept. The age threshold protects uploads whose transaction is still running.
- **Trade-off:** A crashed request can leave a file on disk for up to a day.

### D-083 No dependency on the intl extension for sizes
- **Decision:** `App\Support\FileSize::human()` formats byte sizes in views instead of `Number::fileSize()`.
- **Options considered:** Require the `intl` extension; show raw byte counts.
- **Why this one:** `Number::fileSize()` requires PHP's `intl` extension, which the reference environment does not have; a missing extension should not 500 the ticket page.
- **Trade-off:** English-style formatting only.

## Activity and notifications

### D-084 Explicit activity recording in Actions; final type names
- **Decision:** `TicketActivityRecorder::record(Ticket, ActivityType, ?User, string, array $old = [], array $new = [])` is called explicitly by every Action inside its transaction (empty arrays are stored as NULL). Type values follow the brief: `ticket_created`, `assigned`, `reassigned`, `released`, `auto_assign_skipped`, `priority_changed`, `category_changed`, `status_changed`, `agent_replied`, `customer_replied`, `internal_note_added`, `attachment_added`, `sla_warning`, `sla_breached`, `escalated`, `sla_recalculated`, `reopened`, `imported`, plus `ticket_updated` for subject/description edits (otherwise those edits would be untraceable). A data migration renames older rows (`created`, `updated`, `unassigned`). Descriptions are human readable ("Priority changed from Medium to High", "Assigned to Agent B by Admin A"); a NULL actor renders as "System".
- **Options considered:** Eloquent observers; spatie/laravel-activitylog; explicit calls in Actions.
- **Why this one:** Observers fire on every save, cannot know the acting user or the business meaning (an assignment and a reassignment are the same column change), and turn bulk operations such as the CSV import into log noise. Explicit calls inside the transaction keep the history exactly consistent with the data.
- **Trade-off:** A developer must remember to call the recorder. Mitigated because every change goes through an Action, and `ActionActivityTest` asserts the exact entries (type, old/new values) each Action writes. Two Actions deliberately write two entries: a priority change and a reopen also move the deadline (`sla_recalculated`, D-063). `attachment_added` is reserved for adding files to an existing ticket outside a reply (no such endpoint yet); files on a reply or a new ticket are counted in that entry's `new_values`.

### D-056 Activity type for creation — SUPERSEDED by D-084
- **Decision:** Creation is recorded with the existing `ActivityType::Created` (`created`) rather than a new `ticket_created` value; reopening has its own `reopened` type.
- **Options considered:** A new `ticket_created` type (adopted later in D-084).
- **Why this one:** Activities already belong to a ticket, so the prefix adds nothing; renaming would break existing rows.
- **Trade-off:** None.

### D-085 Append-only enforced in the model and the routes
- **Decision:** `TicketActivity` has no `updated_at`; `update()`, `delete()` and the `updating` / `deleting` events throw `LogicException`; no route writes to activities (tested by scanning the route table). Rows disappear only through the database cascade when their ticket is deleted (tickets are never deleted in the app, D-022).
- **Options considered:** Database triggers or grants only; trusting convention.
- **Why this one:** Model guards stop every application path; the route scan stops anyone adding an edit endpoint by accident.
- **Trade-off:** A raw query-builder `UPDATE` can still bypass the model; database grants would be the next layer if needed.

### D-086 Paginated timeline component
- **Decision:** `<x-ticket-timeline>` shows time (business timezone), actor ("System" for NULL) and description; newest first with an "Oldest first" toggle (`?timeline=oldest`, mapped by whitelist so the raw value never reaches SQL); `simplePaginate(20)` on `ticket_id, created_at` (no COUNT query). Customers only get `ActivityType::isCustomerVisible()` types (D-080).
- **Options considered:** Unpaginated history; offset pagination with a COUNT(*).
- **Why this one:** `simplePaginate` needs no COUNT(*) and is served by `(ticket_id, created_at)`, so a ticket with thousands of entries still renders fast.
- **Trade-off:** No total page count on the timeline (Previous/Next only).

### D-087 Events, queued listeners and queued notifications
- **Decision:** Events `TicketCreated`, `TicketAssigned`, `ReplyAdded` (type + author), `TicketResolved`, `TicketReopened`, `SlaWarningReached`, `SlaBreached` implement `ShouldDispatchAfterCommit`. One listener per event (`App\Modules\Notifications\Listeners\Notify*`, auto-discovered) implements `ShouldQueue` and `ShouldHandleEventsAfterCommit`, decides only who to notify, and sends a Notification. Recipients: TicketCreated → the customer (receipt) + admins and agents specialised in the category; TicketAssigned → the agent and the customer; ReplyAdded → staff public reply: the customer; customer reply: the assignee, or every admin while unassigned; internal note: the assignee if someone else wrote it, NEVER a customer; TicketResolved → the customer; SlaWarningReached → the assignee, or every admin while unassigned; SlaBreached → the assignee and every admin. The actor is never notified of their own action, with one deliberate exception: the customer's creation receipt. `TicketReopened` has no listener yet. `AgentRepliedNotification` refuses an internal note in its constructor as a last guard.
- **Options considered:** Send mail inline in Actions; observers; events + queued listeners + queued notifications.
- **Why this one:** Requests return fast, retries and backoff come from the queue, new channels (Slack, SMS) need no business-code changes, and after-commit dispatch prevents emails about rolled-back changes (tested).
- **Trade-off:** Notifications arrive a moment later, and need a worker running.

### D-088 mail + database channels, separate queues, retries and failure logging
- **Decision:** All notifications extend `TicketNotification` (`ShouldQueue`): `via()` = mail + database; `viaQueues()` puts mail on `notifications` and database rows on `default`; `$tries = 3`, `$backoff = [30, 120, 600]`; `toMail()` renders the Markdown template `mail.tickets.notification`; `toArray()` = ticket id, number, message, url. `failed()` logs the notification class, ticket id, recipient id and error; the job stays in `failed_jobs` for `queue:retry`. The recipient id is captured in `via()`, which Laravel calls per recipient before cloning the notification into per-channel jobs. Worker: `php artisan queue:work redis --queue=notifications,default --tries=3 --backoff=30`.
- **Options considered:** mail only; database only; one queue for everything.
- **Why this one:** The database channel powers the in-app bell with no extra service, mail covers out-of-app alerts, and a separate `notifications` queue means a flood of emails cannot delay assignment jobs.
- **Trade-off:** Two queue jobs per recipient per notification.

### D-089 SLA notifications guarded by the notified-at flags
> Update (Prompt 9): `FlagSlaMilestone` became `EscalateTicket`, run by a queued `EscalateTicketJob`; the scan no longer applies changes itself, and a breach now also escalates (D-091–D-096).
- **Decision:** `sla:scan` (every minute, `withoutOverlapping`, `onOneServer`) finds approaching / breached tickets whose `warning_notified_at` / `breached_notified_at` is NULL (indexed on status + deadline columns) and calls `FlagSlaMilestone`, which sets the flag with a guarded UPDATE (`... WHERE id = ? AND <flag> IS NULL AND <still in that state>`) and only on success records `sla_warning` / `sla_breached` and dispatches the event. The markers are cleared whenever the deadline moves (D-063).
- **Options considered:** Send whenever the scan sees the state ("send and hope the scheduler runs once"); flags set by a guarded UPDATE.
- **Why this one:** The scheduler can overlap or retry safely; a test calls the milestone twice and gets exactly one notification.
- **Trade-off:** Escalation (`is_escalated`, `escalated` activity) is not part of this prompt; the scan only flags and notifies.

### D-090 In-app notification bell
- **Decision:** A bell in the navigation shows `unreadNotifications()->count()`, computed once per request (memoised on the request's attributes in a view composer, tested: one query per page; originally `once()`, replaced in Prompt 10 because `once()` can carry a value across requests in a long-running process). `/notifications` lists them (paginated 20); `POST /notifications/{id}/read` and `/notifications/read-all` always go through `$request->user()->notifications()`, so another user's id is a 404. "Open" redirects to the ticket rebuilt from the stored ticket number, never to a stored URL (no open redirect).
- **Options considered:** Live updates over websockets; no in-app notifications.
- **Why this one:** Database notifications are already written for every event, so the bell costs one indexed COUNT per page.
- **Trade-off:** The count is not live; it refreshes on the next page load.

## Search, dashboard and performance

### D-097 FULLTEXT + MATCH ... AGAINST in boolean mode; no search engine yet
- **Decision:** `Ticket::scopeSearch()` runs `MATCH(subject, description) AGAINST(? IN BOOLEAN MODE)` on the FULLTEXT index. `App\Modules\Tickets\Support\FullTextQuery` turns the input into `+word* +word*` (every word required, prefix-matched): it strips boolean operators (`+ - < > ( ) ~ * " @`), keeps letters with their combining marks, digits and `_`, drops words shorter than InnoDB's minimum token size (3), and caps the query at 8 words. The expression is bound, never interpolated. A query like `TKT-2026-000042` is an exact lookup on the unique `ticket_number` index. If only short words remain, or on SQLite (tests), the fallback is `subject LIKE 'term%' OR description LIKE '%term%'` with wildcards escaped.
- **Options considered:** `LIKE '%term%'`; FULLTEXT; Scout with Meilisearch/Elasticsearch.
- **Why this one:** A leading wildcard cannot use any index and scans the whole table; FULLTEXT stays fast at millions of rows. A search engine adds a service to run, sync and monitor, which this scope does not justify; `TicketFilter::keyword()` is the seam where Scout could plug in later. EXPLAIN on the seeded data: `type=fulltext, key=tickets_subject_description_fulltext`.
- **Trade-off:** Natural-language relevance ranking is not used (results follow the chosen sort); InnoDB stopwords and the 3-character minimum apply. On MySQL the keyword *results* cannot be tested inside a RefreshDatabase transaction (InnoDB FULLTEXT ignores uncommitted rows), so the MySQL test asserts the generated SQL and bindings, and SQLite tests the results.

### D-098 TicketFilter: one method per filter, visibility first
- **Decision:** `App\Modules\Tickets\Filters\TicketFilter` takes the validated `TicketIndexRequest` array and applies `visibleTo($user)` first, then keyword, scope, statuses, priorities, category and created range (business-timezone days converted to UTC bounds), plus the staff-only filters assignee (id or `unassigned`), customer (id), SLA status (`within_sla` / `approaching` / `breached`, through the shared SQL scopes) and escalated. Staff-only filters are validated for everyone but ignored for customers, so a customer who adds `?assigned_agent_id=…` still only sees their own tickets (tested), and the filter bar does not render those fields for them. `scope` defaults to `active` unless explicit statuses are chosen.
- **Options considered:** Query logic in the controller; a generic filter package; Eloquent scopes only.
- **Why this one:** Small, testable methods; one place to add a filter; no way to filter around the visibility rule.
- **Trade-off:** Combining `scope=active` with a non-active status yields an empty list (both conditions apply).

### D-099 Whitelisted sorts and bound values
- **Decision:** `sort` ∈ {`created_at`, `due_at`, `priority`, `updated_at`}, `direction` ∈ {`asc`, `desc`}, validated by `TicketIndexRequest` and applied from the whitelist; `priority` orders by a `CASE` of urgency weights with bound values. Default: staff on the active scope see `due_at asc`; everyone else `created_at desc`. An `id` tie-breaker keeps pages stable. A new `tickets(updated_at)` index serves the updated-at sort.
- **Options considered:** Passing the requested column to `orderBy` (injection risk); no custom sorting.
- **Why this one:** Prevents SQL injection through `ORDER BY` and keeps sorts on indexed columns.
- **Trade-off:** The priority-weight sort is an expression and is sorted in memory over the filtered set (no index can serve it).

### D-100 Offset pagination with a total for the ticket list
- **Decision:** `paginate(15)->withQueryString()` on the narrow `LIST_COLUMNS` with the three eager loads (`creator:id,name`, `assignee:id,name`, `category:id,name`); a test proves 5 and 50 rows cost the same number of queries.
- **Options considered:** `paginate()`; `simplePaginate()`; `cursorPaginate()`.
- **Why this one:** Users expect page numbers and a total in a support desk, and the COUNT is served by narrow indexes. Cursor pagination is the next step where deep paging matters and no total is needed: the activity timeline already uses `simplePaginate` (D-086), and the import error list should use `cursorPaginate`.
- **Trade-off:** Very deep pages cost an OFFSET scan.

### D-101 Customer filter by id, with an autocomplete endpoint
- **Decision:** The list filters on `created_by = ?`. `GET /staff/customers/search?q=` (staff only, `q` ≥ 2 characters) returns at most 10 customers matching a name or email PREFIX, using a new `users(role, name)` index and the unique email index; the filter bar uses it through a small Alpine component.
- **Options considered:** Free-text join on `users.name` in the ticket query; id plus autocomplete.
- **Why this one:** The main ticket query stays on indexed integers.
- **Trade-off:** Prefix matching only (no "contains").

### D-102 Dashboard: conditional aggregation, three more queries, 30-second cache
- **Decision:** `App\Modules\Dashboard\Services\DashboardStatsService::for($user)` returns a cacheable `DashboardStats` DTO (scalars and arrays only). Queries on a cache miss:
  1. ONE `SELECT COALESCE(SUM(CASE WHEN … THEN 1 ELSE 0 END), 0) …` for all eight counters (total active, unassigned, critical, breached, approaching, resolved total, resolved today, escalated), with every value bound;
  2. active tickets grouped by priority;
  3. active / breached per agent, a JOIN on users (top 10);
  4. the last 10 `escalated` activities with ticket and agent eager loaded (plus 2 eager-load queries).
  Six queries in total, tested. Admins share one global entry (`dashboard:admin`); each agent gets their own (`dashboard:agent:{id}`) covering their tickets plus the unassigned queue. Cards link to the matching filtered list (e.g. `/tickets?scope=active&sla_status=breached`); the two tables use CSS bars.
- **Options considered:** Eight `count()` calls; one aggregation query; cache or not.
- **Why this one:** One table pass and a consistent snapshot instead of eight round trips. The short cache protects the database from refresh storms; 30 s of staleness is invisible to humans (no flush on writes, by design).
- **Update (D-128):** Measured on 100k tickets, the single pass was a full table scan. The counters are now two index-only queries, one over active tickets and one over resolved/closed tickets, so the cost follows the open backlog rather than the history.
- **Trade-off:** Numbers can lag by up to 30 seconds. Agents' cards link to the global filtered list (which agents may see), while their numbers cover only their own tickets and the unassigned queue. "Resolved today" links to the closed list sorted by last update rather than an exact filter.

### D-103 EXPLAIN on the heaviest queries (first pass, 200 tickets)
- **Decision:** Checked the keyword, SLA-breached and per-agent dashboard queries with EXPLAIN on the demo data (MariaDB 10.4, after `ANALYZE TABLE`): keyword → `type=fulltext` on `tickets_subject_description_fulltext`; SLA-breached → `range` on `tickets_status_due_at_index`; dashboard per agent → `range` on `tickets_status_due_at_index` + `eq_ref` on `users.PRIMARY`. The per-agent query uses `(status, due_at)` rather than `(assigned_agent_id, status)`: its only condition is `status IN (active)`, and that index also holds the `due_at` the breached count needs. Superseded in scope by D-121, which measures every query on 100k tickets.
- **Options considered:** Trusting index design without measuring.
- **Why this one:** Cheap evidence that the intended indexes are chosen.
- **Trade-off:** 200 rows say little about plans at volume, hence D-121.

### D-121 Measure with EXPLAIN on realistic volume instead of assuming indexes help
- *(Amended by D-137: the seeder and command were removed after the measurements; they remain in git history.)*
- **Decision:** A bulk `PerformanceSeeder` (100k tickets, 300k replies, 50k attachments, ~400k activities) and a `perf:explain` command that drives every list, filter, sort, search, dashboard, ticket page and `sla:scan` through the real HTTP kernel or Artisan command. It captures each SELECT and runs `ANALYZE` on it (estimated and actual rows), with the results in `docs/PERFORMANCE.md`. Index changes are made only where the measurement shows a full scan or a large read for a small result.
- **Options considered:** Reasoning about indexes from the schema (what D-024 did); EXPLAIN on the 200-row demo data (D-103), where every plan looks fine; an APM tool in production only.
- **Why this one:** The spec talks about millions of records. Evidence beats claims, and the measured plans differ from what the schema suggests: on a small table the optimizer picks whatever is cheapest, and at volume the real access paths show. PERFORMANCE.md is a ready-made answer for reviewers, and the command re-runs after any change.
- **Trade-off:** The numbers come from MariaDB 10.4 with a 16 MB buffer pool. Absolute timings are pessimistic; the access paths and rows read are what carry over to MySQL 8.

### D-128 Covering indexes, deferred-join pagination and index-driven scans
- *(Amended by D-137: these indexes are now defined directly in the tickets, notifications and users create migrations; the separate migrations named below no longer exist.)*
- **Decision:** Changes made only where the 100k-ticket measurement (D-121, docs/PERFORMANCE.md) showed a full scan or thousands of row reads for a small result:
  1. **Widened indexes** (migration `2026_11_07_100003`), each keeping its leading columns:
     - `created_at` → `(created_at, status)`;
     - `(status, sla_warning_at)` → `(+ due_at, warning_notified_at)`;
     - `(status, due_at)` → `(+ breached_notified_at)`;
     - `(category_id | priority, status)` → `(+ due_at)`;
     - `(assigned_agent_id, status)` → `tickets_agent_cover_index (assigned_agent_id, status, due_at, priority, is_escalated, sla_warning_at, resolved_at)`, which covers an agent's dashboard ("assignee = me or nobody");
     - `(status, is_escalated, due_at)` → `(status, is_escalated, escalation_level, due_at)`;
     - `(created_by, created_at)` → `(+ status)`, so a customer's own list is index-only;
     - new `tickets_dashboard_cover_index (status, priority, assigned_agent_id, is_escalated, due_at, sla_warning_at)`.

     Notifications got `(notifiable…, read_at)`, `(notifiable…, created_at)` and `(read_at)` (migration `…100004`).
  2. **Deferred-join pagination** (`App\Support\DeferredPagination`) for the ticket list and the unassigned queue. The page of ids comes from the covering indexes, then only the rows shown are loaded by primary key.
  3. **Dashboard counters** split into an active-tickets query and a finished-tickets query, both index-only. This updates D-102's "one table pass": that pass was a full table scan.
  4. **`sla:scan`** reads candidate ids through the SLA indexes instead of `chunkById`, whose `ORDER BY id LIMIT` made the optimizer walk the whole primary key every minute.
- **Options considered:** Leave the plans alone (they are fine on 200 rows); a search engine or a read model for lists; forcing indexes with hints (`FORCE INDEX` couples code to index names and to one optimizer); keyset (cursor) pagination, which loses page numbers and total counts in the UI.
- **Why this one:**
  - **Before:** list and dashboard queries read one full row per *candidate*: the SLA-approaching count took 39 s cold, tickets-by-agent 58 s, the priority sort 112 s, an agent's dashboard about 64 s, and closed lists and the admin dashboard counters did full table scans.
  - **After:** they read index entries per candidate and full rows only for the 15–20 rows shown. The cost now grows with the size of the matching index range, not with table width or history.
  - Widening reuses the existing indexes instead of adding parallel ones, and every former query keeps its leading-column match.
- **Trade-off:** Tickets carry more and wider secondary indexes, so each insert and update costs more (acceptable: tickets are written far less often than lists are read). A list page now takes one more query (ids, then rows); the count is still constant per page.

## CSV import

### D-104 Queued job with a status record
- **Decision:** `POST /admin/imports` (admin only; `mimes:csv,txt` + `extensions:csv,txt`, max `support.import.max_kb` = 50 MB) stores the file on the private disk as `imports/{uuid}.csv`, creates a `pending` `ticket_imports` row, queues `ProcessTicketImport` on the `imports` queue and redirects at once to the status page. The page polls `GET /admin/imports/{id}/status` (JSON) every 3 seconds and shows a progress bar; the row-error report streams as CSV (`streamDownload` over `rowErrors()->cursor()`, ordered by row number, spreadsheet formulas neutralised). The routes live in the admin group (`/admin/imports`) rather than at `/imports`.
- **Options considered:** Process in the HTTP request; a queued job with a status record.
- **Why this one:** Large files exceed request time limits and would tie up PHP workers; the admin gets instant feedback and live progress.
- **Trade-off:** Needs a queue worker for `imports`.

### D-105 Streaming reader without a new dependency
- **Decision:** `App\Modules\Imports\Support\CsvReader` streams the file with `SplFileObject` (READ_CSV, SKIP_EMPTY) wrapped in generators / `LazyCollection`. It reads the header once (trimmed, lower-cased, BOM removed), counts rows in one streaming pass for the progress bar, and yields `['row' => spreadsheet row number, 'data' => assoc|null, 'raw' => list]`.
- **Options considered:** `file()` / `collect()` of the whole file; Laravel Excel (maatwebsite); `league/csv`; `SplFileObject` + generators.
- **Why this one:** Constant memory regardless of file size, measured: peak memory grows by about 9–14 MB for a 10,000-row file in the test. Laravel Excel is powerful but heavy for plain CSV; `league/csv` would also be fine, but `SplFileObject` already does what is needed with zero dependencies. Switch to Laravel Excel only if XLSX is needed.
- **Trade-off:** The row number counts CSV records, so a record with an embedded line break counts as one row, which is what a spreadsheet shows.

### D-106 Chunked bulk inserts with batch lookups
- **Decision:** `ImportTicketRows` handles `support.import.chunk_size` (500) rows at a time: it validates each row with `Validator::make`, then runs batch lookups for the whole chunk (users by email, categories by name or slug, already-imported references; duplicates inside the chunk via a set). It reserves all ticket numbers for the chunk with ONE `TicketNumberGenerator::reserve($n)`, computes SLA per row (no queries: rules are cached), and in ONE transaction bulk-creates missing customers, `insertOrIgnore`s the tickets, bulk-inserts `imported` activities and row errors, and updates the counters plus `last_processed_row`.
- **Options considered:** `Ticket::create()` per row; chunked bulk inserts.
- **Why this one:** One INSERT per chunk is orders of magnitude faster than one per row, and the per-chunk transaction bounds lock time and provides the checkpoint. Bulk inserts deliberately bypass model events: no `TicketCreated`, no emails, no auto-assignment (tested); the `imported` activities are inserted explicitly. Batch lookups remove the N+1 problem from the importer.
- **Trade-off:** Bulk inserts need raw values (enum values, UTC date strings), and the `insertOrIgnore` path needs a re-read by ticket number to know which rows actually landed.

### D-107 Uniqueness enforced by the database, pre-check for speed
- **Decision:** `(source, external_reference)` is unique (D-024). Duplicates are pre-checked (same chunk, earlier chunks, or tickets already in the database) and reported with row number and reason; `insertOrIgnore` plus re-reading the inserted ticket numbers catches anything that slips past the pre-check (e.g. two imports at once) and reports it as a duplicate too.
- **Options considered:** Pre-check only (racy); index only (no friendly row-level messages).
- **Why this one:** The pre-check is a performance optimisation and gives good messages; the unique index is the guarantee.
- **Trade-off:** A row rejected by the index after its number was reserved leaves a gap in the numbering (only in that race).

### D-108 Sequential, resumable job instead of parallel batches
- **Decision:** `ProcessTicketImport` (timeout 3600, `tries = 1`, `failOnTimeout`) runs `RunTicketImport`, which skips `last_processed_row` data rows and continues. A crash rolls back only the chunk in progress (its number reservation included, so no gaps); `queue:retry` or the Resume button continues from the last committed chunk, and re-processing a committed chunk cannot duplicate anything (tested). `failed()` and the action both mark the import `failed` with `error_message` and log the context. A dedicated connection (`imports-redis` / `imports-database`) has `retry_after = 3700`, longer than the job timeout, so no second worker picks up a running import.
- **Options considered:** `Bus::batch()` of parallel chunk jobs; one sequential job with a checkpoint.
- **Why this one:** Parallel workers would contend for the ticket-number counter and complicate duplicate detection, and throughput is already high with bulk inserts (10,000 rows in about 9 s on SQLite, about 75 s on the local MariaDB). Batching is the documented scale-up path.
- **Trade-off:** One import uses one worker for its whole duration.

### D-109 Import data rules
- **Decision:** Priority and status are matched case-insensitively. A category matches by name (case-insensitive) or slug. An unknown customer email creates a customer (name from the email's local part, no verified email) with a random, never-revealed password hash (one per chunk, so 10,000 new customers do not cost 10,000 bcrypt hashes). No welcome email is sent unless `SUPPORT_IMPORT_WELCOME_NEW_CUSTOMERS=true`, which sends a password-reset link. An email belonging to staff is rejected as a customer, and `assigned_agent_email` must belong to an agent (set directly: no auto-assignment, no notification). `created_at` is optional ISO 8601, converted to UTC. Resolved/closed rows get `resolved_at` / `closed_at` = the row's `created_at` (the only timestamp in the file), so imports neither inflate "resolved today" nor fake breaches. Active historical rows whose warning or deadline already passed are marked as notified, so `sla:scan` does not send a retroactive storm. Ticket numbers use the import year. When the import finishes, the uploader gets one summary notification instead of per-ticket emails.
- **Options considered:** Rejecting unknown customers; strict case-sensitive enums; per-row welcome emails.
- **Why this one:** Imports must succeed on real, messy legacy data without surprising customers with emails or false SLA breaches.
- **Trade-off:** Historical tickets keep SLA targets computed from their `created_at` under today's rules.

## Testing and quality

### D-009 Strict Eloquent outside production
- **Decision:** `Model::shouldBeStrict(! app()->isProduction())` (lazy loading, silently discarded attributes and missing attributes all throw) plus an explicit `Model::preventLazyLoading(...)`.
- **Options considered:** Manual N+1 review; Debugbar/Telescope query counts; strict mode.
- **Why this one:** Turns an N+1 into an exception during development and tests — stronger evidence than manual checking. Production only logs, never breaks a page.
- **Trade-off:** Every relation used in a view must be eager loaded explicitly, including in tests.

### D-010 Test database: MySQL when available, otherwise SQLite in-memory
- **Decision:** `phpunit.xml` defaults to SQLite `:memory:`. `tests/Pest.php` switches to MySQL database `support_desk_testing` when `DB_TEST_CONNECTION=mysql` is set (via the environment or `.env.testing`) and the server is reachable.
- **Options considered:** SQLite only; MySQL only; auto-detect.
- **Why this one:** SQLite needs no setup, so a clone runs the tests immediately; MySQL runs the same migrations against the production engine (index syntax, strict mode, collation). The fallback keeps the suite green on machines without MySQL.
- **Trade-off:** SQLite runs can hide MySQL-only behaviour; CI should set the MySQL variable.

### D-014 Architecture rules enforced by Pest arch tests
- **Decision:** `tests/Unit/ArchitectureTest.php` fails the suite if controllers use the `DB` facade, Actions/Services depend on the HTTP layer, FormRequests do not extend `FormRequest`, or debug calls (`dd`, `dump`, ...) remain.
- **Options considered:** Convention only; code review; static analysis (PHPStan rules); Pest arch tests.
- **Why this one:** The layer rules (docs/GUIDE.md) become executable at no extra tooling cost.
- **Trade-off:** Arch rules only check namespaces/usages, not deeper design quality.

### D-110 Test depth follows risk
- **Decision:** The deepest tests sit where a bug would hurt most:
  1. Authorization and internal-note secrecy: a policy matrix over every role and ability, route access by role, an IDOR sweep of every ticket, attachment and notification endpoint, and note secrecy checked in the HTML page, JSON, email, in-app notifications and timeline.
  2. Business-hours SLA maths: table-driven unit tests over nights, weekends, holidays and limits.
  3. Concurrent assignment and ticket numbering: a deterministic compare-and-set test, rollback without gaps, and the unique index as the safety net.
  4. Escalation idempotency: repeated `sla:scan` runs and replayed jobs change nothing.
  5. CSV import correctness: 10,000 rows with exact counts, contiguous numbers, per-row errors and resume.

  UI snapshots and CSS are deliberately not tested.
- **Options considered:** Even coverage across every class; coverage-percentage targets; browser/snapshot tests.
- **Why this one:** The spec evaluates which areas are treated as critical. A leaked internal note or a wrong deadline matters more than a misaligned button. Snapshot tests break on every cosmetic change and catch no logic errors.
- **Trade-off:** Blade layout and styling regressions are caught only by manual review.

### D-111 Unit tests for pure logic, feature tests for flows
- **Decision:** `tests/Unit` holds logic that needs no HTTP, queue or events: BusinessCalendar, the SLA calculator, SlaStatusResolver, TicketStatus transitions, ticket-number formatting, CSV parsing, FULLTEXT sanitising, filename cleaning and strategy ordering. Flows (request → policy → action → events → listeners) are feature tests, grouped by spec area (`Auth`, `Authorization`, `Tickets`, `Assignment`, `Conversation`, `Sla`, `Notifications`, `Search`, `Dashboard`, `Import`, `EndToEnd`). Strategy ordering is defined in one SQL query taken under row locks, so its unit test includes the database but nothing else.
- **Options considered:** Everything as feature tests (slow, vague failures); mocking the database for every unit.
- **Why this one:** Pure tests are fast and pinpoint the broken rule. Feature tests prove the pieces are wired together, including authorization and transactions.
- **Trade-off:** A few rules are tested twice, once as a unit and once inside a flow. That is deliberate.

### D-112 Real MySQL in CI, SQLite as the zero-setup fallback
- **Decision:** CI runs the whole suite against a MySQL 8 service. Locally, `tests/bootstrap.php` uses MySQL when it is reachable and falls back to SQLite `:memory:` otherwise. The engine-specific tests (row locks, the 20-process claim race) run only on MySQL and are skipped, with a reason, on SQLite.
- **Options considered:** SQLite only; MySQL only.
- **Why this one:** These all differ between SQLite and MySQL, and they are exactly the risky parts:
  - locks (`FOR UPDATE`)
  - FULLTEXT boolean mode
  - unique-constraint behaviour under `insertOrIgnore`
  - JSON columns
  - strict mode

  SQLite keeps the suite runnable on any clone in about 35 seconds.
- **Trade-off:** A run on SQLite alone is not proof. CI on MySQL is the gate.

### D-113 Deterministic compare-and-set test instead of relying on timing
- **Decision:** Concurrent accept is proven by a deterministic test. Two agents load the same unassigned ticket and both run the guarded UPDATE; exactly one affected-row count is 1. A second test asserts that the WHERE clause carries the guard. The real 20-process race (`tests/Concurrency`) is kept as extra evidence on MySQL only.
- **Options considered:** Only multi-process races; mocking the database.
- **Why this one:** The guard in the WHERE clause is what makes the claim safe, and the deterministic test exercises it on every run. A timing race can pass by luck, and it is slow and flaky in CI.
- **Trade-off:** The deterministic test does not interleave real connections. The process race covers that where MySQL is available.

### D-114 One deterministic world in the base TestCase
- **Decision:** `Tests\TestCase::setUp()` does five things:
  - freezes the clock (`Carbon::setTestNow` via `travelTo`) at Monday 2026-11-09 10:00 Dhaka;
  - seeds Faker from the test name;
  - fakes the `private` disk;
  - blocks all outgoing HTTP (`Http::preventStrayRequests`);
  - disables Vite.

  Factories compute dates from `now()` instead of Faker's real clock. Default holiday dates are 60+ days ahead, so they never fall inside a test's SLA window. Tests that need another moment travel explicitly. Nothing sleeps.
- **Options considered:** Freeze time only in the tests that obviously need it.
- **Why this one:** Freezing time everywhere surfaced a hidden real-clock dependency at once: the orphan-purge test relied on the file system's real "now". A failure now reproduces exactly, on any day.
- **Trade-off:** A test that forgets to travel runs at a fixed Monday morning, not "now".

### D-115 What is faked, and the one test that fakes nothing
- **Decision:** Storage is always faked and HTTP is always blocked. The queue is the in-process `sync` driver and mail uses the in-memory `array` transport, so nothing leaves the process. Tests that assert on dispatch (jobs, events, recipients) fake `Queue`, `Event` or `Notification` locally.

  Exactly one test, `tests/Feature/EndToEnd/TicketCreationChainTest.php`, fakes nothing. It covers the HTTP request, the after-commit event, queued listeners, the auto-assign job and rendered mail delivered to the transport. It asserts the exact recipients and subjects.
- **Options considered:** `Queue::fake()` and `Notification::fake()` globally.
- **Why this one:** Global queue and notification fakes would turn many flow tests into "a job was pushed" checks, and listener and recipient logic would go untested. The sync driver still needs no external infrastructure: no Redis, no SMTP.
- **Trade-off:** Feature tests run listeners inline, so a slow listener slows the suite. None are slow today.

### D-116 Tests that need real commits
- **Decision:** `TestCase::commitTestTransaction()` commits RefreshDatabase's wrapping transaction. Two tests use it:
  - the 10,000-row import, so its chunks commit as in production;
  - the keyword search test, because InnoDB FULLTEXT only sees committed rows. It now runs on MySQL instead of being skipped.

  `tearDown()` then empties every table (TRUNCATE for big tables, DELETE for small ones; migrations seed no rows) and reopens a transaction. Otherwise Laravel would re-run every migration for the next test.
- **Options considered:** Leave the import inside the test transaction; `DatabaseTruncation` for the whole suite; re-migrate after the test.
- **Why this one:** On the local MariaDB, rolling back about 20,000 uncommitted rows took about 70 seconds and one re-migration about 57 seconds. Emptying the tables takes seconds, and the test becomes more faithful to production.
- **Trade-off:** These tests must never run against a database whose data matters. The test database is dedicated (`DB_TEST_DATABASE`).

### D-117 Coverage and feature tour mapped to the 17 spec sections
- *(Superseded by D-133: TESTING.md, REVIEW_CHECKLIST.md and the README tour were removed; docs/GUIDE.md maps features to files.)*
- **Decision:** `docs/TESTING.md` (coverage table), the README feature tour and `docs/REVIEW_CHECKLIST.md` follow the 17 numbered sections of the assessment specification, from Ticket Management through Automated Testing. A first version used sections inferred from the prompts while the spec text was not available; once it was provided, the tables were relabelled to its exact sections.
- **Options considered:** Mapping by prompt number; one row per test folder.
- **Why this one:** Reviewers read the spec; one row per spec section answers "where is section N, and how is it proven" directly.
- **Trade-off:** Some tests serve several sections (for example `AttachmentPolicyTest` serves both 3 and 7); each is counted once, under its primary section.

### D-118 One CI job: style, static analysis, tests on MySQL 8
- **Decision:** `.github/workflows/tests.yml` runs on pushes to `main` and on pull requests. The job steps are:
  1. PHP 8.2, the minimum supported version.
  2. A MySQL 8 service with its data directory on tmpfs.
  3. `composer install`.
  4. `pint --test`.
  5. Larastan.
  6. `php artisan test --parallel --processes=4`; Laravel creates one test database per process.

  The upstream laravel/laravel workflows (issues, pull requests, changelog) were removed. They call Laravel's own organisation workflows.
- **Options considered:** A PHP-version matrix; separate jobs per check.
- **Why this one:** One job installs dependencies once, and a single PHP version matches the deployment target. Style and analysis failures show up within a minute.
- **Trade-off:** Newer PHP versions are not tested in CI.

### D-119 Larastan level 5, findings fixed rather than baselined
- **Decision:** Larastan 3 at level 5 over `app`, `config`, `database` and `routes`, with `parseModelCastsMethod: true`. Without that option, Larastan ignores `casts()` methods and types every enum and date attribute as `string`, which produced about 90 false positives. The 19 real findings were fixed at the source:
  - `Builder<Ticket>` generics so local scopes resolve;
  - a return type widened to the `Expression` contract that `DB::raw()` returns;
  - the CSV row docblock corrected to `list<string|null>`;
  - the driver name read from the model's connection;
  - two needless nullsafe calls removed;
  - the email-verification bug in D-120.

  No baseline and no ignore rules.
- **Options considered:** Level 8 (mostly generics noise for a Laravel app); a baseline of current errors; no static analysis.
- **Why this one:** A baseline hides exactly the findings worth reading. Level 5 checks argument and return types without demanding generics on every collection.
- **Trade-off:** Levels 6+ (missing iterable value types everywhere) are not enforced.
