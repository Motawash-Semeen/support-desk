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
