# Code guide: how Support Desk works inside

Everything in the codebase, explained in plain language: what each part is for, which file it lives in, and
how the parts work together. It covers every module, controller, action, service, model, job, command and
listener.

For what the screens look like, see [UI_GUIDE.md](UI_GUIDE.md). For *why* things are built this way, see
[DECISIONS.md](DECISIONS.md).

**Contents**

1. [Laravel in two minutes](#1-laravel-in-two-minutes)
2. [The big picture](#2-the-big-picture)
3. [Folder map](#3-folder-map)
4. [The core (outside the modules)](#4-the-core-outside-the-modules)
5. [Module: Tickets](#5-module-tickets)
6. [Module: Conversation](#6-module-conversation)
7. [Module: Assignment](#7-module-assignment)
8. [Module: Sla](#8-module-sla)
9. [Module: Activity](#9-module-activity)
10. [Module: Notifications](#10-module-notifications)
11. [Module: Dashboard](#11-module-dashboard)
12. [Module: Imports](#12-module-imports)
13. [Five flows from start to finish](#13-five-flows-from-start-to-finish)
14. [Security in one list](#14-security-in-one-list)
15. [Tests](#15-tests)
16. [I want to change …](#16-i-want-to-change-)
17. [Known limitations](#17-known-limitations)

---

## 1. Laravel in two minutes

The app is built with **Laravel 12** (a PHP framework). If a word below is new, this is what it means here:

| Word | Plain meaning | Example in this app |
|---|---|---|
| **Route** | Maps a URL to the code that answers it | `GET /tickets` → `TicketController@index` |
| **Middleware** | A check that runs before the controller | `auth` (must be signed in), `role:admin` (must be an admin) |
| **Controller** | Receives the request and sends back a page or redirect. Here, controllers are *thin*: they only check, validate and hand off | `TicketController` |
| **Form request** | A class with the validation rules for one form | `StoreTicketRequest` (subject required, max 255…) |
| **Policy** | Answers "may this user do this to this record?" | `TicketPolicy::work()` (the assigned agent or an admin) |
| **Gate** | Same idea for actions that have no record. Only one is left (`viewLogViewer`, required by the log viewer); role-only pages use `role:` middleware instead | `viewLogViewer` |
| **Action** | One class per business task that does the real work, inside a database transaction | `CreateTicket`, `ChangeTicketStatus` |
| **Service** | Reusable logic used by several actions | `BusinessCalendar` (deadline maths) |
| **Model** | One database table as a PHP class, with its relationships | `Ticket`, `User` |
| **Migration** | A file that creates a database table | `create_tickets_table.php` |
| **Enum** | A fixed list of allowed values | `TicketStatus`: open, in_progress, … |
| **View** | The HTML template (Blade) | `Tickets::show` |
| **Event / listener** | "Something happened" / "react to it" (here: send notifications) | `ReplyAdded` → `NotifyReplyAdded` |
| **Job / queue** | Work done in the background by a worker, so the user doesn't wait | `AutoAssignTicket` |
| **Command / scheduler** | A terminal command, run automatically on a timetable | `sla:scan` every minute |
| **Transaction** | A group of database changes that are saved together or not at all | every action |

---

## 2. The big picture

Support Desk is one Laravel app with server-rendered pages (Blade templates, a bit of Alpine.js for
interactivity, Tailwind for styling).

The code is split into **modules**, one folder per feature, in `app/Modules` (using the L5Modular package).
Anything that is not a feature (users, login, layouts, shared helpers) stays in Laravel's normal folders.

**Every request follows the same path:**

```
Browser asks for a URL
  → route                (app/Modules/<Module>/routes/web.php)
  → middleware           auth, verified, role:admin|agent
  → controller           checks permission, validates, calls ONE action, returns a page
      → form request     validation rules
      → policy           who may do it to this ticket (role-only pages: the role middleware)
      → action           the real work, inside one database transaction
          → services     reusable logic (deadline maths, ticket numbers, history)
          → models       read and write the tables
          → event        fired only after the transaction commits
              → queued listener → notification (email + bell entry)
```

**The one rule to remember:** business logic lives in **actions** and **services**, never in controllers or
templates. To understand any feature, open its action. An automatic test (`tests/Unit/ArchitectureTest.php`)
fails if a controller talks to the database directly.

---

## 3. Folder map

```
app/
  Modules/                     the 8 feature modules (sections 5–12)
  Models/User.php              users (role: admin | agent | customer)
  Models/Notification.php      in-app notifications (the bell)
  Enums/Role.php               admin, agent, customer
  Http/Controllers/            sign-in and sign-up (Auth/*), profile, the /dashboard redirect
  Http/Middleware/             EnsureRole (role:...), SecurityHeaders (security headers)
  Providers/AppServiceProvider.php   app-wide setup (permissions, rate limits, …)
  Support/                     small shared helpers: BusinessTime, FileSize, DeferredPagination
  View/Components/             <x-app-layout>, <x-guest-layout>

bootstrap/app.php              middleware, error handling, where module listeners/commands live
config/support.php             ALL business settings (deadlines, hours, uploads, assignment, import)
config/log-viewer.php          the admin log viewer
routes/web.php                 core URLs only (home, /dashboard, profile); routes/auth.php = sign-in URLs
routes/console.php             the scheduler: what runs every minute / day

resources/views/
  layouts/                     the page shell: app.blade.php, navigation.blade.php (sidebar), guest.blade.php
  components/                  shared UI pieces: <x-badge>, <x-card>, <x-icon>, <x-stat-card>, buttons, inputs
  auth/, profile/              sign-in, sign-up, password, profile pages
  errors/                      friendly error pages: 403, 404, 419, 429, 500, 503 (+ 4xx/5xx fallbacks)
  vendor/                      overrides of package views (pagination, log viewer)
  welcome.blade.php            landing page (only the banner is shown)
resources/css/app.css          design tokens and component classes (btn, card, field-input, table …)
tailwind.config.js             colours: brand (teal) and ink (slate)

database/migrations/           core tables: users, sessions, cache, jobs
database/factories/            fake-data makers for tests
database/seeders/              sample data (team, customers, 42 realistic tickets)
tests/                         automated tests (section 15)
docs/                          these guides, DECISIONS.md, PERFORMANCE.md
```

**Every module has the same inside layout** (only the folders it needs):

```
app/Modules/<Module>/
  Models/  Enums/  Actions/  Services/  Policies/  Events/  Jobs/  Listeners/
  Http/Controllers/  Http/Requests/
  Console/Commands/  resources/views/  routes/web.php  database/migrations/
```

A module's templates are used as `view('Tickets::index')`, and its components as `<x-Activity::ticket-timeline>`.

---

## 4. The core (outside the modules)

### Start-up and app-wide setup

**`bootstrap/app.php`** is the app's starting configuration:

- registers the `role` middleware and adds `SecurityHeaders` to every page;
- tells Laravel to find event listeners in `app/Modules/*/Listeners` and commands in
  `app/Modules/*/Console/Commands`;
- error handling: adds the route and URL to every logged error, and when saving fails because of a database
  problem, sends the user back to the form with a friendly message instead of an error page.

**`app/Providers/AppServiceProvider.php`** runs on every request and sets up:

| Method | What it does |
|---|---|
| `register()` | Tells Laravel which class to use for the SLA rules and calculator |
| `configureEloquent()` | Outside production, makes database mistakes loud: loading data in a loop by accident (N+1 queries) or writing a field that isn't allowed throws an error, so tests catch it |
| `configureModules()` | Connects `Ticket` to `TicketPolicy` and `Attachment` to `AttachmentPolicy`, and tells Laravel where test factories are (module models live outside the usual folder) |
| `configureAuthorization()` | Defines the one gate, `viewLogViewer` (admins), which the log-viewer package requires. Pages that depend only on the role are guarded by `role:` middleware on their URLs instead. Also feeds the sidebar its unread-notification count, counted once per page |
| `configureRouteBindings()` | Turns `{ticket}` in a URL into a ticket **by its number**. For customers it looks only among their own tickets, so someone else's ticket is a 404, exactly like a ticket that doesn't exist. For agents, `TicketPolicy` gives the same 404 for tickets that aren't theirs |
| `configureRateLimits()` | Per-user limits per minute on creating tickets, replying, downloading and importing (numbers in `config/support.php`) |
| `boot()` | Password rules: at least 8 characters; in production at least 10, mixed case, numbers, and not found in known data breaches |

### Middleware

| File | Plain meaning |
|---|---|
| `Http/Middleware/EnsureRole.php` | `role:admin` or `role:admin,agent` on a group of URLs: anyone else gets 403. A coarse check only; each action still asks its policy |
| `Http/Middleware/SecurityHeaders.php` | Adds browser security headers to every page: a strict Content-Security-Policy (only our own scripts, plus inline scripts carrying a one-time code called a nonce), no framing, no MIME sniffing, and HTTPS-only (HSTS) on HTTPS |

### Users and roles

| File | Plain meaning |
|---|---|
| `Models/User.php` | A person. `role` is admin, agent or customer, and **cannot be set from any form** (only seeders/admin code). Has helpers `isAdmin()`, `isAgent()`, `isCustomer()`, `isStaff()`, scopes `agents()` and `availableAgents()`, and relationships: tickets created, tickets assigned, replies, imports, categories (an agent's specialities). Email verification is required |
| `Enums/Role.php` | The three roles, with a label and `isStaff()` |
| `Models/Notification.php` | A bell entry. Read entries older than 90 days are deleted nightly |

### Core controllers

| Controller | URLs | What each method does |
|---|---|---|
| `DashboardController` | `GET /dashboard` | Sends staff to the staff dashboard and customers to their ticket list |
| `ProfileController` | `GET/PATCH/DELETE /profile` | `edit` shows the profile page; `update` saves name and email (a changed email must be verified again); `destroy` deletes the account after asking for the password, but refuses for accounts with ticket history (their tickets and audit trail must stay) |
| `Auth/*` (from Laravel Breeze) | `/login`, `/register`, `/logout`, `/forgot-password`, `/reset-password`, `/verify-email`, `/confirm-password`, `/password` | Standard sign-in, sign-up (always as a customer), sign-out, password reset, email verification, password change |

### Shared helpers and layouts

| File | Plain meaning |
|---|---|
| `Support/BusinessTime.php` | Shows a stored UTC time in the business timezone (Asia/Dhaka), e.g. "08 Nov 2026, 16:10" |
| `Support/FileSize.php` | Turns bytes into "5.0 MB" |
| `Support/DeferredPagination.php` | Paging that stays fast on 100k tickets: first fetch only the ids for the page, then the full rows for just those 15 (section 5) |
| `View/Components/AppLayout.php` | `<x-app-layout title="…">`: the signed-in page shell (sidebar, header, flash messages) |
| `View/Components/GuestLayout.php` | `<x-guest-layout>`: the signed-out shell for sign-in pages |

### The scheduler (`routes/console.php`)

One cron line on the server runs `php artisan schedule:run` every minute, which starts these:

| When | Command | What it does |
|---|---|---|
| every minute | `sla:scan` | Finds tickets that reached a warning, breach or level-2 moment and queues an escalation job for each |
| every 5 minutes | `tickets:assign-unassigned` | Tries again to auto-assign tickets nobody owns |
| daily 02:00 | `tickets:auto-close-resolved` | Closes tickets resolved more than 3 days ago |
| daily 03:30 | `attachments:purge-orphans` | Deletes uploaded files that were left behind by a failed save |
| daily 04:00 | `model:prune` | Deletes read notifications older than 90 days |
| weekly | `queue:prune-failed` | Deletes failed-job records older than a week |

### Settings (`config/support.php`)

All business settings in one file, each overridable with a `SUPPORT_*` value in `.env`:

- ticket number prefix and padding (`TKT`, 6 digits);
- business timezone (Asia/Dhaka) and default business hours;
- default deadlines per priority and the warning threshold (80%);
- attachments: disk, max size (5 MB), max files (5), allowed types;
- assignment: workload weights (critical 3, high 2, medium 1, low 1), auto-assign on create, retry batch size;
- escalation level-2 delay (120 minutes), auto-close delay (3 days), notification pruning (90 days);
- rate limits per minute; import chunk size and maximum file size.

---

## 5. Module: Tickets

**Purpose:** creating, viewing, editing and finishing tickets; ticket numbers; the ticket list with search and
filters. **Tables:** `categories`, `ticket_sequences`, `tickets`.

### Models and enums

| File | Plain meaning |
|---|---|
| `Models/Ticket.php` | The heart of the app. Key columns: `ticket_number`, `subject`, `description`, `priority`, `status`, `category_id`, `created_by` (customer), `assigned_agent_id`, deadline columns (`sla_minutes`, `sla_warning_at`, `due_at`), `first_response_at`, `resolved_at`, `closed_at`, escalation columns. Relationships: creator, assignee, category, slaPolicy, replies, attachments, activities. Useful scopes: `active()`, `closedOrResolved()`, `unassigned()`, `visibleTo($user)` (who may see what: admins everything, agents their own tickets plus active unassigned ones, customers their own), `slaBreached()`, `slaApproaching()`, `search($words)`. `slaStatus()` gives the SLA state of one ticket |
| `Models/Category.php` | A ticket category (Billing, Bug Report, …), with its tickets and its specialist agents |
| `Models/TicketSequence.php` | One counter row per year, used to make ticket numbers |
| `Enums/TicketStatus.php` | open, in_progress, waiting_for_customer, resolved, closed. **`allowedTransitions()` is the single list of legal status moves**; `adminOnlyTransitions()` adds "reopen a closed ticket" for admins |
| `Enums/TicketPriority.php` | low, medium, high, critical, with label, colour, sort weight and default deadline |
| `Enums/TicketSource.php` | web or import |

### Controllers

**`TicketController`**: every method checks permission, validates, calls one action and returns.

| Method | URL | What it does |
|---|---|---|
| `index` | `GET /tickets` | The ticket list: applies `TicketFilter` (search, filters, sort) and pages it quickly with `DeferredPagination`. Admins see every ticket, agents only tickets assigned to them, customers only their own. Passes categories, agents and the chosen customer to the filter bar |
| `create` | `GET /tickets/create` | Shows the new-ticket form |
| `store` | `POST /tickets` | Validates with `StoreTicketRequest`, calls `CreateTicket`, redirects to the new ticket |
| `show` | `GET /tickets/{ticket}` | The ticket page: replies, attachments and activities **already filtered for the viewer** (customers get no internal notes), plus agents for the assign dropdown |
| `edit` / `update` | `GET …/edit`, `PATCH /tickets/{ticket}` | Edit form / save through `UpdateTicket` |
| `changeStatus` | `POST /tickets/{ticket}/status` | **Every** status change: Resolve, Close and Reopen buttons and the status dropdown all post their target status here, and it calls `ChangeTicketStatus` |

**`CustomerSearchController`** (`GET /staff/customers/search`, staff only): the customer autocomplete in the filter
bar. Matches the start of a name or email and returns at most 10 customers.

### Form requests (validation)

| File | Checks |
|---|---|
| `StoreTicketRequest` | subject, description, priority, active category, attachments |
| `UpdateTicketRequest` | the same fields; priority and category are accepted **only** from users allowed to change them (otherwise a validation error, not a silent drop) |
| `TicketIndexRequest` | every list parameter (search, filters, sort, scope). Staff-only filters are validated for everyone but ignored for customers |
| `ChangeTicketStatusRequest` | the target status, the optional reason, and the matching permission: `close` for Closed, `reopen` for leaving a finished state, `work` for everything else |
| `CustomerSearchRequest` | the autocomplete text |

### Actions (the real work)

| Action | What it does |
|---|---|
| `CreateTicket` | In one transaction: takes the next ticket number, calculates the deadline in business hours, saves the ticket, writes "Ticket … created" to the history, stores attachments, and (after commit) fires `TicketCreated` and queues auto-assignment. If anything fails, everything is undone and stored files are deleted |
| `UpdateTicket` | Saves edited fields, writes one history entry per changed field, and recalculates the deadline when the priority changes |
| `ChangeTicketStatus` | **The only place a status changes.** Locks the ticket, refuses illegal moves (`InvalidTicketTransition`, shown as a form error), sets or clears `resolved_at`/`closed_at`, restarts the deadline on reopen, writes history, fires events |

### Services, filters and support

| File | Plain meaning |
|---|---|
| `Services/TicketNumberGenerator.php` | Makes `TKT-2026-000042`. Locks this year's counter row, adds one, and saves it **in the same transaction as the ticket**, so two people can't get the same number and a failed save gives the number back (no gaps). `reserve($n)` takes a block of numbers for imports |
| `Filters/TicketFilter.php` | Turns the filter bar into a database query. Always starts with `visibleTo()`, so no filter combination can show tickets the user may not see; for agents it also keeps only tickets assigned to them (unassigned ones are in the queue), and ignores the agent filter. Handles search (exact ticket number, or full-text words), status, priority, category, agent, customer, dates, SLA state, escalated, scope tabs and sorting |
| `Support/FullTextQuery.php` | Makes typed search words safe for MySQL full-text search ("printer jam" → every word required, prefix match), stripping special characters |

### Other files

| File | Plain meaning |
|---|---|
| `Policies/TicketPolicy.php` | Who may do what to one ticket: `view`, `work` (change priority, category or working status, resolve, write internal notes: the assigned agent or an admin), `update` (subject/description; also the customer while open), `reply`, `close`, `reopen`, `assign`, `accept`, `release`, `viewInternalNotes`. Answers depend on role, ownership (the customer who created it, the agent assigned) and the ticket's state. **Visibility comes first** (`canSee()`): admins see every ticket, agents their own plus active unassigned ones, customers their own; any ticket outside that is a 404 for every ability, except an agent's `accept`, so losing a race shows "just taken" instead. Admins pass almost everything |
| `Events/*` | `TicketCreated`, `TicketUpdated`, `TicketStatusChanged`, `TicketResolved`, `TicketReopened`, all fired after commit |
| `Exceptions/InvalidTicketTransition.php` | An illegal status move, shown as a form error instead of an error page |
| `Console/Commands/AutoCloseResolvedTickets.php` | `tickets:auto-close-resolved`: closes tickets resolved more than 3 days ago, one at a time, through `ChangeTicketStatus` (so the rules and history still apply) |
| Views | `index` (list), `show` (ticket page), `create`, `edit`, partials `filter-bar` (search, tabs, chips, filter panel), `form`, `status-actions` |
| Routes | `/tickets…` and `/staff/customers/search` |

---

## 6. Module: Conversation

**Purpose:** public replies, internal notes and file attachments. **Tables:** `ticket_replies`, `attachments`.

| File | Plain meaning |
|---|---|
| `Models/TicketReply.php` | One message on a ticket, either public or an internal note (`isInternal()`) |
| `Models/Attachment.php` | A file's details (original name, type, size, checksum). The file itself is on a **private** disk under a random UUID name, never reachable by URL |
| `Enums/ReplyType.php` | public or internal_note |
| `Controllers/TicketReplyController` | `store` (`POST /tickets/{ticket}/replies`): checks permission, validates with `StoreReplyRequest`, calls `AddReply` |
| `Controllers/AttachmentController` | `download` (`GET /attachments/{id}/download`): the **only** way to get a file. Checks `AttachmentPolicy`, then streams the file from the private disk with strict headers |
| `Requests/StoreReplyRequest` | Message and files. **Customers can only send public replies**: the server decides the type, and a tampered form asking for a note is rejected |
| `Actions/AddReply` | In one transaction: saves the reply and files, writes history, sets `first_response_at` on the first public staff reply, and moves the status (agent replies on Open → In progress; customer replies on Waiting → In progress). Fires `ReplyAdded` after commit. Closed tickets refuse public replies (`ReplyNotAllowed`) |
| `Actions/StoreAttachments` | Saves uploaded files to `tickets/{id}/{year}/{month}/{uuid}.{ext}` on the private disk and records them. If the transaction fails, the files are deleted again |
| `Policies/AttachmentPolicy` | A file is visible exactly when its ticket is, except files on an internal note follow the internal-note rule. A denied request gets a 404, so nobody can probe file ids |
| `Rules/SafeUpload` | Extra file checks: no empty files, no hidden executable extensions like `invoice.php.pdf` |
| `Support/AttachmentRules` | The shared upload rules (count, size, allowed extensions **and** the real file content type), built from the settings |
| `Support/SafeFilename` | Cleans a file name before it is stored or sent back in a download |
| `Console/Commands/PurgeOrphanAttachments` | `attachments:purge-orphans`: deletes files with no database row older than 24 hours |
| `Events/ReplyAdded`, `Exceptions/ReplyNotAllowed` | The after-commit event and the closed-ticket error |
| Views | `reply-form` (reply / internal note tabs), `attachments`, `attachment-input` |

---

## 7. Module: Assignment

**Purpose:** who works on a ticket: agents accepting from the queue, admins assigning, and automatic
assignment. **Table:** `agent_category` (which categories each agent specialises in).

### Controllers

**`TicketAssignmentController`**

| Method | URL | What it does |
|---|---|---|
| `queue` | `GET /staff/unassigned` | The unassigned queue, most urgent deadline first |
| `accept` | `POST /tickets/{ticket}/accept` | An agent takes the ticket (`AcceptTicket`) |
| `assign` | `POST /tickets/{ticket}/assign` | An admin assigns or reassigns (`AssignTicket`) |
| `release` | `POST /tickets/{ticket}/release` | Hands the ticket back to the queue (`ReleaseTicket`) |

**`AvailabilityController`** (`PATCH /profile/availability`, agents only): the Available / Unavailable switch on
the profile page.

### Actions

| Action | What it does |
|---|---|
| `ClaimTicket` | **The race-safe core.** One database statement: "assign this ticket to me *only if it is still unassigned*". If two agents try at once, the database lets exactly one succeed; the other gets 0 rows changed and sees a conflict message. On success it records history and notifies |
| `AcceptTicket` | An agent accepting from the queue. Uses `ClaimTicket`; a double click by the winner still counts as success |
| `AssignTicket` | An admin assigns or reassigns. Guarded by the assignee the admin *saw* when the page loaded, so it never overwrites someone else's newer change (shown as a conflict instead) |
| `ReleaseTicket` | Puts the ticket back in the queue (status Open), with the same guard |
| `SetAgentAvailability` | Saves an agent's availability |
| `Concerns/GuardedAssignment` | Small pieces shared by the three guarded writes above |

### Services and jobs

| File | Plain meaning |
|---|---|
| `Services/CandidateAgents.php` | Who *may* get a ticket: available agents who specialise in its category, or generalists (no categories). If nobody matches, any available agent. Locks those agents' rows so two jobs can't both pick the same person |
| `Services/LeastWorkloadStrategy.php` | Who *should* get it: the lowest workload (each active ticket counts by priority: critical 3, high 2, medium 1, low 1), then whoever was never assigned or waited longest, then the lowest id. One database query for all candidates |
| `Jobs/AutoAssignTicket.php` | Background job: picks an agent with the strategy and assigns with `ClaimTicket`. If nobody is available, records "no available agent" once and leaves the ticket in the queue. Only one job per ticket can be waiting at a time |
| `Console/Commands/AssignUnassignedTickets.php` | `tickets:assign-unassigned` (every 5 minutes): queues `AutoAssignTicket` again for unassigned active tickets, most urgent first |

### Other files

| File | Plain meaning |
|---|---|
| `Exceptions/AssignmentConflict.php` | "Someone else changed the assignment first": shown as a 409 page with a clear message, never overwriting anything |
| `Events/TicketAssigned`, `TicketReleased` | Fired after commit; assignment triggers notifications |
| Requests | `AssignTicketRequest` (agent + the assignee the admin saw), `UpdateAvailabilityRequest` |
| Views | `unassigned` (queue), `conflict` (409 page), partials `assignment` (sidebar box on the ticket page), `availability-form` (profile) |

---

## 8. Module: Sla

**Purpose:** deadlines counted in business hours, warnings, breaches and escalation, and the admin settings
screen. **Tables:** `sla_policies` (target per priority), `business_hours` (one row per weekday), `holidays`.

### How a deadline is calculated (services)

| File | Plain meaning |
|---|---|
| `Services/BusinessCalendar.php` | **Pure maths, no database.** `addBusinessMinutes(start, minutes)`: jumps to the next opening time if outside hours, uses up the minutes left today, then moves day by day, skipping closed days and holidays. Works in seconds so nothing is rounded. Example: Thursday 17:00 + 2 business hours = Sunday 10:00 |
| `Services/BusinessHoursSlaCalculator.php` | For a priority and start time, returns `warningAt` (80% of the target) and `dueAt`, both in UTC |
| `Services/SlaCalculator.php`, `SlaRules.php` | Small interfaces the rest of the app uses for the two classes above (tests swap in fakes through them) |
| `Services/SlaPolicyRepository.php` | Reads the three rule tables once, caches them for an hour, and builds the calendar. Falls back to `config/support.php` if a row is missing. The cache is cleared whenever an admin saves |
| `Services/SlaStatusResolver.php` | Works out the SLA state: within SLA, at risk, breached (active tickets); met or missed (finished ones). The same rules exist in PHP (one ticket) and SQL (lists, filters, dashboard) side by side, and a test checks they agree |
| `Services/TicketSlaRecalculator.php` | Recalculates one ticket's deadline (after a priority change or reopen, or from `sla:recalculate`) and records it in the history |
| `Services/SlaPolicyRule.php`, `SlaResult.php` | Small data holders: the rule for one priority; the calculated warning and due times |

### Escalation

| File | Plain meaning |
|---|---|
| `Console/Commands/ScanSla.php` | `sla:scan` (every minute). Finds active tickets that passed their warning time, passed their deadline, or have been breached for 2 more hours, and queues one `EscalateTicketJob` for each |
| `Jobs/EscalateTicketJob.php` | Runs one escalation step for one ticket in the background |
| `Actions/EscalateTicket.php` | **Does each step exactly once**, using a guarded update ("set breached_notified_at = now *only if it is still empty*"). If the step was already done, 0 rows change and nothing happens, so repeated scans or retried jobs never send duplicate emails. Breach: marks the ticket escalated (level 1), writes history, notifies the agent and all admins, and tries auto-assignment if nobody owns it. Level 2 notifies admins again |
| `Enums/EscalationKind.php` | warning, breach, level_two |
| `Enums/SlaStatus.php` | within_sla, approaching (at risk), breached, met, missed, with labels and colours |
| `Events/SlaWarningReached`, `SlaBreached`, `TicketEscalated` | Fired once per deadline; listeners send the notifications |
| `Console/Commands/RecalculateSla.php` | `sla:recalculate --open [--dry-run]`: applies changed rules to active tickets (finished ones keep their history) |

### Settings screen

| File | Plain meaning |
|---|---|
| `Controllers/SlaSettingsController` | `index` shows the screen; `updatePolicies`, `updateBusinessHours`, `storeHoliday` and `destroyHoliday` save changes (admins only) |
| Actions | `UpdateSlaPolicies`, `UpdateBusinessHours`, `AddHoliday`, `RemoveHoliday`: each saves one part of the rules |
| Requests | `UpdateSlaPoliciesRequest` (minutes, threshold, active), `UpdateBusinessHoursRequest` (closing after opening, at least one working day), `StoreHolidayRequest` (unique date) |
| `Models/SlaPolicy`, `BusinessHour`, `Holiday` | The three rule tables |
| `Observers/SlaRulesObserver.php` | Clears the cached rules after any of the three tables changes |
| `Exceptions/NoBusinessHoursConfigured.php` | Raised if the calendar finds no working time at all (for example, every day closed), instead of looping forever |
| View | `settings` |

---

## 9. Module: Activity

**Purpose:** the ticket's history, the audit trail from the specification. **Table:** `ticket_activities`.

| File | Plain meaning |
|---|---|
| `Services/TicketActivityRecorder.php` | `record(ticket, type, actor, description, old values, new values)`. **Every action that changes a ticket calls this inside its own transaction**, so the change and its history entry are saved together or not at all. Actor `null` means the system did it |
| `Models/TicketActivity.php` | One history entry. **Append-only**: the model refuses updates and deletes |
| `Enums/ActivityType.php` | Every kind of entry (created, assigned, reassigned, released, status changed, priority changed, agent replied, internal note added, SLA warning, SLA breached, escalated, imported, …) with a label, and `isCustomerVisible()`: customers see changes to their ticket and replies, but not assignment, internal notes or SLA/escalation steps |
| `resources/views/components/ticket-timeline.blade.php` | `<x-Activity::ticket-timeline>`: the Activity box on the ticket page, newest or oldest first, paged |

---

## 10. Module: Notifications

**Purpose:** emails and bell entries when something happens to a ticket. **Table:** `notifications`.

**How it works:** an action fires an event after its transaction commits. A **listener** (one per event,
running in the background) decides **who** to notify. A **notification** class decides **what** they get. Each
notification goes out twice: an email (on the `notifications` queue) and a bell entry (database). Two rules apply
everywhere: never notify the person who did it, and never notify the same person twice for one event.

| Listener | Event | Who is notified |
|---|---|---|
| `NotifyTicketCreated` | ticket created | the customer (a receipt), the admins, and agents specialised in its category |
| `NotifyTicketAssigned` | ticket assigned | the agent (unless they took it themselves) and the customer |
| `NotifyReplyAdded` | reply added | internal note → the assigned agent only, **never the customer**; agent reply → the customer; customer reply → the assigned agent, or the admins if unassigned |
| `NotifyTicketResolved` | ticket resolved | the customer |
| `NotifySlaWarning` | at risk | the assigned agent, or the admins if unassigned |
| `NotifySlaBreached` | deadline passed | the assigned agent and all admins |
| `NotifyTicketEscalated` | escalation level 2 | all admins |

| File | Plain meaning |
|---|---|
| `Notifications/TicketNotification.php` | The shared base: queued, mail + bell, retries, logs failures. All ticket notifications extend it |
| `Notifications/*Notification.php` | One per message: TicketCreated, TicketAssigned, AgentReplied (can never carry an internal note), CustomerReplied, InternalNoteAdded (staff only), TicketResolved, SlaApproaching, SlaBreached, TicketEscalated |
| `Listeners/Concerns/ResolvesRecipients.php` | The shared "who" helpers (assignee, customer, admins) and the two rules above |
| `Controllers/NotificationController` | `index` (`GET /notifications`) lists your notifications; `markRead` and `markAllRead` mark them. Every query is limited to your own notifications, so another user's id is a 404 |
| Views | `index` (the notifications page), `mail/ticket` (the email template) |

---

## 11. Module: Dashboard

**Purpose:** the staff dashboard numbers.

| File | Plain meaning |
|---|---|
| `Controllers/DashboardController` | `GET /staff/dashboard` (staff only): gets the numbers and shows the page |
| `Services/DashboardStatsService.php` | Calculates every card with as few queries as possible: one query that counts all the active-ticket numbers at once (open, unassigned, critical, breached, at risk, escalated), one for the finished numbers, plus per priority, per agent and the recent escalations. Admins see everything; agents see their own tickets plus the unassigned queue. Results are cached for 30 seconds |
| `Services/DashboardStats.php` | The plain result object the page displays |
| View | `index` |

---

## 12. Module: Imports

**Purpose:** importing tickets from a CSV file, in the background, safely resumable. **Tables:**
`ticket_imports` (one per upload, with progress), `ticket_import_errors` (one per rejected row).

| File | Plain meaning |
|---|---|
| `Controllers/TicketImportController` | Admins only. `index` (upload page and history), `store` (upload), `show` (progress page), `status` (progress as JSON, polled by the page), `errors` (download rejected rows as CSV), `sample` (download a sample file), `resume` (continue a failed import) |
| `Requests/StoreTicketImportRequest` | A CSV file up to 50 MB |
| `Actions/StartTicketImport` | Saves the file privately, records a "pending" import and queues the job; returns immediately |
| `Jobs/ProcessTicketImport` | The background job (on its own `imports` queue). If it crashes, the import is marked failed and **Resume** continues where it stopped |
| `Actions/RunTicketImport` | Reads the file row by row, skips rows already done, and hands chunks of 500 rows to `ImportTicketRows`. Saves a checkpoint after each chunk |
| `Actions/ImportTicketRows` | One chunk in one transaction: validates every row, looks up customers, agents and categories in bulk, rejects duplicates (in the file or already imported), creates missing customer accounts, reserves a block of ticket numbers, inserts the tickets with their deadline and history in bulk, and records rejected rows with their row number |
| `Support/CsvReader` | Streams the file one line at a time, so memory stays flat even for 100,000 rows |
| `Models/TicketImport`, `TicketImportError` | The import and its rejected rows |
| `Enums/ImportStatus` | pending, processing, completed, failed |
| `Notifications/TicketImportFinishedNotification` | One summary to the admin who uploaded, instead of one per ticket |
| Views | `index`, `show` |

The error-report download protects against spreadsheet formula injection: cells starting with `=`, `+`, `-` or
`@` are made safe.

---

## 13. Five flows from start to finish

### A customer opens a ticket

1. `POST /tickets` → `TicketController@store`.
2. `StoreTicketRequest` validates; `TicketPolicy::create` allows it.
3. `CreateTicket`, in one transaction:
   - `TicketNumberGenerator` locks this year's counter → `TKT-2026-000043`;
   - `BusinessHoursSlaCalculator` works out `sla_warning_at` and `due_at`;
   - the ticket row is saved, history gets "Ticket … created", attachments are stored.
4. After commit: `TicketCreated` → `NotifyTicketCreated` emails the customer and staff; `AutoAssignTicket` is queued.
5. The customer is redirected to their new ticket.

### Auto-assignment

1. A queue worker runs `AutoAssignTicket`.
2. `CandidateAgents` lists and locks the suitable agents; `LeastWorkloadStrategy` picks the least busy.
3. `ClaimTicket` assigns only if still unassigned; history gets "Auto-assigned to …"; status becomes *In progress*.
4. `TicketAssigned` → `NotifyTicketAssigned` emails the agent and the customer.

### An agent replies

1. `POST /tickets/{ticket}/replies` → `TicketReplyController@store` → `StoreReplyRequest` (decides public or note).
2. `AddReply`: saves the reply, writes history, sets the first-response time, moves the status if needed.
3. `ReplyAdded` → `NotifyReplyAdded`: a public reply emails the customer; a note emails only staff.

### A deadline is missed

1. Every minute `sla:scan` finds the ticket and queues `EscalateTicketJob`.
2. `EscalateTicket` sets `breached_notified_at` **only if it was empty**, marks it escalated, writes history.
3. `SlaBreached` → `NotifySlaBreached` emails the agent and every admin.
4. Two hours later, the same thing happens for level 2 (`TicketEscalated` → admins).

### A CSV import

1. Admin uploads → `StartTicketImport` saves the file, queues `ProcessTicketImport`.
2. `RunTicketImport` streams the file in chunks of 500; each chunk is one `ImportTicketRows` transaction.
3. The progress page polls `status`; rejected rows can be downloaded; a crash can be resumed.
4. At the end, the admin gets one summary notification.

---

## 14. Security in one list

- Every action on one ticket asks `TicketPolicy` (in the controller or its form request); every URL group has
  `auth` and `verified`, and role-only pages (dashboard, queue, admin screens) have `role:` middleware.
- Customers see only their own tickets; agents see only tickets assigned to them, plus the unassigned queue;
  admins see everything. Any other ticket gives a 404, the same as a missing ticket, so numbers can't be probed.
- Internal notes are filtered on the server for the page, emails and the timeline.
- Uploads: allowed extension **and** real file content checked, size and count limits, stored privately under
  random names, downloaded only through `AttachmentPolicy`.
- Roles can't be set from any form; every model lists the fields forms may fill (`$fillable`).
- CSRF protection on every form, strict security headers (`SecurityHeaders`), rate limits on writes and
  downloads.
- `tests/Feature/Security/RouteAuthorizationAuditTest.php` fails the build if any URL lacks authorization.

---

## 15. Tests

```bash
php artisan test                           # MySQL support_desk_testing if reachable, else SQLite in memory
DB_TEST_CONNECTION=sqlite php artisan test # force SQLite (no setup, about 30 s)
php artisan test tests/Feature/Sla         # one area
php artisan test --filter="internal note"  # by name
vendor/bin/pint --test                     # code style
vendor/bin/phpstan analyse                 # static analysis (Larastan level 5)
```

- `tests/Feature/<Area>` matches the feature: Tickets, Assignment, Conversation, Sla, Notifications, Search,
  Dashboard, Import, Activity, Logs, Authorization, Security, Database, Bootstrap, EndToEnd.
- `tests/Unit` holds pure logic (calendar maths, enums, CSV reader) and `ArchitectureTest.php` (the layer rules,
  checked automatically).
- `tests/Concurrency` races 20 real processes for one ticket (MySQL only).
- Time is frozen at Mon 2026-11-09 10:00 Asia/Dhaka in every test (`tests/TestCase.php`); mail, storage and
  outside HTTP are faked.
- CI (`.github/workflows/tests.yml`) runs Pint, Larastan and all tests on MySQL 8 on every push.

---

## 16. I want to change …

| I want to … | Go to |
|---|---|
| change deadlines, business hours, holidays | the admin screen `/admin/sla-settings`; defaults in `config/support.php` |
| change the timezone, upload limits, auto-close days | `config/support.php` (each value has a `SUPPORT_*` env variable) |
| add a field to tickets | migration in `app/Modules/Tickets/database/migrations`, `$fillable` and `casts()` in `Models/Ticket.php`, rules in `StoreTicketRequest` / `UpdateTicketRequest`, the form in `resources/views/partials/form.blade.php`, and `database/factories/TicketFactory.php` |
| allow a new status move | `Tickets/Enums/TicketStatus.php` → `allowedTransitions()` |
| change who can do something | `Tickets/Policies/TicketPolicy.php` for ticket actions; the `role:` middleware in the module's `routes/web.php` for role-only pages |
| change which tickets someone can see | `TicketPolicy::canSee()` and `Ticket::scopeVisibleTo()` (keep them the same), plus the agent rule in `TicketFilter::apply()` |
| change who receives an email | the matching `Notifications/Listeners/Notify*.php` |
| change an email's text | `Notifications/Notifications/*Notification.php` and `Notifications/resources/views/mail/ticket.blade.php` |
| add a history entry type | a case in `Activity/Enums/ActivityType.php` (label + customer visibility), then call the recorder from the action |
| change the auto-assignment rule | `Assignment/Services/LeastWorkloadStrategy.php`; workload weights in `config/support.php` |
| change the ticket list, filters or search | `Tickets/Filters/TicketFilter.php`, `Tickets/resources/views/index.blade.php` and `partials/filter-bar.blade.php` |
| change dashboard numbers | `Dashboard/Services/DashboardStatsService.php` and `Dashboard/resources/views/index.blade.php` |
| change the sidebar | `resources/views/layouts/navigation.blade.php` |
| change colours or button styles | `tailwind.config.js` and `resources/css/app.css`, then `npm run build` |
| change what runs on a schedule | `routes/console.php` |
| change the sample data | `database/seeders/UserSeeder.php` and `database/seeders/data/tickets.php`, then `php artisan migrate:fresh --seed` |
| add a page to a module | a route in the module's `routes/web.php`, a controller method, a view in its `resources/views` |
| add a new module | a folder in `app/Modules` with the layout from section 3; add its migrations folder to `phpstan.neon`, and map any new policy in `AppServiceProvider::configureModules` |

Test factories always stay in `database/factories` (L5Modular's loader for factories inside modules doesn't work
with current Laravel).

---

## 17. Known limitations

- The deadline keeps counting while a ticket waits for the customer.
- One business timezone and one calendar for everyone; only a resolution deadline (no first-response deadline).
- Search covers the subject and description, not replies.
- No user-management screen: staff accounts come from the seeder.
- Nobody is emailed or paged about errors; admins check the logs at `/admin/log-viewer`.
- Uploads are type-checked but not virus-scanned.
