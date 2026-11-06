# Decisions

Each entry: **Decision / Options considered / Why this one / Trade-off.**

---

## Prompt 1 — Bootstrap

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
- **Decision:** Business logic in `app/Actions/*` (one class per use case) and `app/Services/*` (reusable domain logic). Controllers only authorize, validate, call an Action, respond.
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

### D-014 Architecture rules enforced by Pest arch tests
- **Decision:** `tests/Unit/ArchitectureTest.php` fails the suite if controllers use the `DB` facade, Actions/Services depend on the HTTP layer, FormRequests do not extend `FormRequest`, or debug calls (`dd`, `dump`, ...) remain.
- **Options considered:** Convention only; code review; static analysis (PHPStan rules); Pest arch tests.
- **Why this one:** The layer rules in ARCHITECTURE.md become executable at no extra tooling cost.
- **Trade-off:** Arch rules only check namespaces/usages, not deeper design quality.

### D-015 Application timezone fixed to UTC in code
- **Decision:** `config/app.php` keeps `'timezone' => 'UTC'` (no `APP_TIMEZONE` override); the business zone is `config('support.timezone')`.
- **Options considered:** Business timezone as app timezone; UTC via env; UTC in code.
- **Why this one:** Storing UTC must not depend on an env value someone could change; conversion happens only in the SLA calculator and views.
- **Trade-off:** Views must convert explicitly (a helper will do this).

---

## Prompt 2 — Database layer

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

### D-026 Mass-assignment policy
- **Decision:** `User::$fillable` excludes `role`, `is_available`, `last_assigned_at` (changed only through Actions via `forceFill`). Ticket `$fillable` lists the columns Actions write; FormRequests whitelist user input and Actions map validated fields explicitly. Strict mode makes any non-fillable key throw instead of being dropped.
- **Options considered:** `$guarded = []`; minimal fillable everywhere; explicit fillable plus explicit mapping.
- **Why this one:** Role escalation through an extra form field is impossible even if a controller passes too much.
- **Trade-off:** Seeders and role-changing Actions must use `forceFill`.

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

### D-029 Status transitions allow reopening
- **Decision:** `Open → InProgress | WaitingForCustomer | Resolved | Closed`; `InProgress → WaitingForCustomer | Resolved | Closed`; `WaitingForCustomer → InProgress | Resolved | Closed`; `Resolved → Closed | Open`; `Closed → Open`.
- **Options considered:** Closed as terminal; reopening allowed.
- **Why this one:** Customers often reply to a resolved or closed ticket; reopening keeps one thread. The simplest reasonable reading of the brief.
- **Trade-off:** A reopened ticket needs a fresh SLA decision (handled by the transition Action).

### D-030 Small schema choices beyond the brief
- **Decision:** `categories` has timestamps; `business_hours`, `holidays` and `ticket_sequences` have none; `attachments.stored_name` is unique; `ticket_import_errors.row_number` keeps its name (reserved in MySQL 8, safe because Laravel always backtick-quotes identifiers).
- **Why this one:** Timestamps only where they carry meaning; a unique on-disk name stops two rows pointing at one file.
- **Trade-off:** Hand-written raw SQL must quote `row_number`.

### D-031 `selectListColumns()` separate from `withListRelations()`
- **Decision:** `withListRelations()` eager loads `creator:id,name`, `assignee:id,name`, `category:id,name`; `selectListColumns()` limits ticket columns to `Ticket::LIST_COLUMNS` (no `description` TEXT).
- **Options considered:** One combined scope; two composable scopes.
- **Why this one:** Lists use both; detail pages reuse the relations without losing columns. Under strict mode, reading an unselected column throws, so a list needing more columns fails loudly in tests. A test proves 3 rows and 30 rows cost the same 5 queries.
- **Trade-off:** Callers combine the two scopes.

---

## Prompt 3 — Authorization

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
- **Decision:** `Ticket::getRouteKeyName()` = `ticket_number`. Downloads go through `/tickets/{ticket}/attachments/{attachment}` with `->scopeBindings()`, streamed by `AttachmentController` from the private disk at `attachments/{ticket_id}/{stored_name}`, with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. A missing file logs a warning with context and returns a friendly 404.
- **Options considered:** Numeric ids in URLs; `/attachments/{id}` without the ticket; public URLs.
- **Why this one:** Human-readable URLs; the scoped binding guarantees the attachment belongs to the ticket in the URL; the policy then decides access. Files never get a public URL.
- **Trade-off:** Downloads pass through PHP.

### D-040 Blade helpers are cosmetic
- **Decision:** `@staff ... @endstaff` (`Blade::if`) and `@can(...)` hide staff UI and unavailable actions. No view check is relied on for security.
- **Why this one:** Clear templates; every request is still authorized server-side.
- **Trade-off:** None beyond keeping UI and policy names aligned.

### D-041 Landing pages and the first role-gated screens
- **Decision:** Breeze's `/dashboard` became a redirect (staff → `/staff/dashboard`, customers → `/tickets`). Added read-only screens so the authorization layers are exercised end to end: ticket list and detail (internal notes and activity for staff only), staff dashboard (five indexed counts + the most overdue tickets), admin SLA settings overview (editing comes with the SLA prompt). Import routes will join the `role:admin` group.
- **Options considered:** Keep Breeze's static dashboard; build stubs only; minimal real screens.
- **Why this one:** The 404 and internal-note rules can be tested over HTTP, not only at the policy level.
- **Trade-off:** These screens will be extended by later prompts.

### D-042 Breeze auth and profile controllers are exempt from policies
- **Decision:** Login, registration, password and profile controllers keep Breeze's behaviour without `authorize()` calls.
- **Why this one:** They act only on the authenticated user (or a guest), so there is no other record to protect; their access is enforced by `auth`/`guest` middleware, `current_password` and signed URLs.
- **Trade-off:** The "every controller calls authorize()" rule applies to application controllers, not the scaffolded auth ones.

### D-043 `App\Support\BusinessTime` for display
- **Decision:** Views format timestamps with `BusinessTime::format()` (UTC → `config('support.timezone')`).
- **Why this one:** One place for the conversion D-015 promised; storage and queries stay UTC.
- **Trade-off:** Views must use the helper rather than `->format()` directly.
