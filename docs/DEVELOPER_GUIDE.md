# Developer guide

Everything a new developer needs to understand Support Desk and work on it:

- where to start and in what order to read;
- which packages are installed and why;
- the core code, explained line by line;
- how a task is done here, start to finish, with roles and permissions as the full worked example;
- every design decision, with the alternatives and why they lost.

For what the screens look like, see [UI_GUIDE.md](UI_GUIDE.md). This guide is about the code.

**Contents**

1. [Your first day: a reading path](#1-your-first-day-a-reading-path)
2. [Packages](#2-packages)
3. [How the code is organised](#3-how-the-code-is-organised)
4. [The core code, line by line](#4-the-core-code-line-by-line)
5. [Worked example: roles and permissions, every line](#5-worked-example-roles-and-permissions-every-line)
6. [The workflow for any task](#6-the-workflow-for-any-task)
7. [Decisions and the alternatives we rejected](#7-decisions-and-the-alternatives-we-rejected)
8. [Tests and quality checks](#8-tests-and-quality-checks)
9. [I want to change …](#9-i-want-to-change-)
10. [Known limitations](#10-known-limitations)

---

## 1. Your first day: a reading path

Read in this order. Each step builds on the one before.

**Step 1. Run it.** Follow the README: `composer install`, `npm install && npm run build`,
`php artisan migrate --seed`, `php artisan serve`. Sign in as the admin, one agent and one customer (accounts
in the README and the UI guide; every password is `password`). Click through each role's screens with the
[UI guide](UI_GUIDE.md) open. Five minutes of clicking explains more than an hour of reading.

**Step 2. Learn the vocabulary.** Open `app/Modules/Tickets/Enums/TicketStatus.php` and
`app/Modules/Tickets/Enums/TicketPriority.php`. Everything else talks about these values.

**Step 3. Follow one URL.** Open `app/Modules/Tickets/routes/web.php`, pick `POST /tickets` (create a ticket),
and follow it: `TicketController::store` → `StoreTicketRequest` (validation) → `CreateTicket` (the work).
Section 4 walks through exactly this path.

**Step 4. Read the three most important actions.** `CreateTicket` (numbering, deadline, history),
`ClaimTicket` (two agents can't take the same ticket) and `AddReply` (conversation rules, internal notes).
Every other action follows the same pattern.

**Step 5. Read the permissions.** `app/Modules/Tickets/Policies/TicketPolicy.php` decides who may do what to
a ticket. Section 5 explains it line by line.

**Step 6. Read the deadline maths.** `app/Modules/Sla/Services/BusinessCalendar.php`. It's pure logic with no
database, so you can read it top to bottom.

**Step 7. Read the tests next to the code.** `tests/Feature/<Area>` mirrors the modules. A test is the
fastest way to see how a class is meant to be used. Run one area: `php artisan test tests/Feature/Tickets`.

**Step 8. Make a tiny change.** For example, add a category in `database/seeders/CategorySeeder.php`, reseed,
and see it in the form. Then read section 6 before anything bigger.

---

## 2. Packages

### PHP packages (`composer.json`)

**Used by the running app (`require`):**

| Package | What it does here | Why this one |
|---|---|---|
| `laravel/framework` 12 | The framework: routing, database, queues, mail, Blade, everything | Latest Laravel that runs on PHP 8.2 |
| `artem-schander/l5-modular` | Lets each feature live in its own folder under `app/Modules`, with its own routes, views and migrations | Light: one service provider, no setup per module (section 7) |
| `opcodesio/log-viewer` | The admin page at `/admin/log-viewer` for reading `storage/logs` | A maintained, ready-made viewer; we only configure access |
| `predis/predis` | Talks to Redis for the production queue and cache | Pure PHP, so no PHP extension to install (works on Windows too) |
| `laravel/tinker` | `php artisan tinker`, an interactive console for poking at data | Standard Laravel tool |

**Only for development and tests (`require-dev`):**

| Package | What it does here |
|---|---|
| `laravel/breeze` | Generated the sign-in, sign-up, password and profile screens (we restyled them). Not needed at runtime |
| `pestphp/pest` + `pest-plugin-laravel` | The test framework: readable tests (`it('…', function () {…})`), architecture rules |
| `larastan/larastan` | Static analysis: finds type errors without running the code (`vendor/bin/phpstan analyse`) |
| `laravel/pint` | Code style fixer (`vendor/bin/pint`) |
| `fakerphp/faker` | Fake names and text for test factories |
| `mockery/mockery` | Test doubles (Laravel's fakes use it) |
| `nunomaduro/collision` | Readable error output in the terminal |
| `laravel/pail` | `php artisan pail`: follows the log live (used by `composer run dev`) |

### JavaScript and CSS packages (`package.json`)

All are build tools; the browser gets one small CSS file and one small JS file.

| Package | What it does here |
|---|---|
| `vite` + `laravel-vite-plugin` | Builds `resources/css/app.css` and `resources/js/app.js` into `public/build` |
| `tailwindcss` + `@tailwindcss/forms` + `postcss` + `autoprefixer` | The CSS framework and its form styles |
| `alpinejs` | Small interactive bits written in the HTML (`x-data`, `@click`): menus, the filter panel, the reply tabs |
| `@fontsource-variable/inter`, `@fontsource/jetbrains-mono` | The two fonts, bundled with the app instead of loaded from a font CDN (so the security policy can stay strict) |
| `concurrently` | Runs server, queue, logs and Vite together for `composer run dev` |

---

## 3. How the code is organised

### The big idea

The app is split into **8 feature modules** in `app/Modules`. Anything that isn't a feature (users, sign-in,
layouts, shared helpers) stays in Laravel's normal folders.

```
app/Modules/
  Tickets/        create, view, edit, status changes, numbering, list and filters
  Conversation/   replies, internal notes, attachments
  Assignment/     accept, assign, release, automatic assignment, availability
  Sla/            business-hours deadlines, warnings, breaches, escalation, SLA settings
  Activity/       the ticket history (audit trail)
  Notifications/  emails and bell notifications
  Dashboard/      the staff dashboard numbers
  Imports/        CSV import
```

Every module has the same inside layout (only the folders it needs):

```
Models/            database tables as classes
Enums/             fixed lists of values (statuses, priorities…)
Actions/           ONE class per business task (CreateTicket, AddReply…): the real work
Services/          logic shared by several actions (deadline maths, ticket numbers…)
Policies/          who may do what to a record
Http/Controllers/  receive the request, return a page; thin
Http/Requests/     validation rules for one form
Events/ Listeners/ "something happened" / "react to it" (notifications)
Jobs/              background work
Console/Commands/  terminal commands, run by the scheduler
resources/views/   templates, used as view('Tickets::index')
routes/web.php     the module's URLs
database/migrations/  the module's tables
```

Outside the modules:

```
app/Models/User.php              users; role is admin | agent | customer
app/Http/Middleware/EnsureRole   the role:admin / role:admin,agent check on URL groups
app/Http/Middleware/SecurityHeaders   security headers on every page
app/Providers/AppServiceProvider.php  app-wide setup (section 5 explains the auth parts)
app/Support/                     small helpers: BusinessTime (show UTC in Dhaka time), FileSize, DeferredPagination
config/support.php               EVERY business setting (deadlines, hours, uploads, assignment, import)
routes/web.php, routes/auth.php  core URLs (home, profile, sign-in)
routes/console.php               the scheduler: what runs every minute / day
resources/views/                 layouts, shared components, sign-in pages, error pages
database/seeders/                sample team, customers and 42 realistic tickets
tests/                           automated tests (section 8)
```

### The one rule

**Business logic lives in Actions and Services.** Controllers only check permission, validate, call one
action and return a page. Templates only display. An automatic test (`tests/Unit/ArchitectureTest.php`) fails
if a controller uses the database directly, or if an action or service depends on HTTP classes.

---

## 4. The core code, line by line

### 4.1 A URL and its controller

`app/Modules/Tickets/routes/web.php`:

```php
Route::middleware(['auth', 'verified'])->group(function () {
```
Every URL inside needs a signed-in user (`auth`) whose email is verified (`verified`). Guests are sent to sign in.

```php
    Route::controller(TicketController::class)->prefix('tickets')->name('tickets.')->group(function () {
```
All routes in this group use `TicketController`, start with `/tickets`, and have names starting with `tickets.`
(so code says `route('tickets.show', $ticket)` instead of hard-coding URLs).

```php
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:tickets')->name('store');
```
The list, the new-ticket form, and saving a new ticket. `throttle:tickets` limits how many tickets one user
can create per minute (the number is in `config/support.php`).

```php
        Route::get('/{ticket}', 'show')->name('show');
        Route::get('/{ticket}/edit', 'edit')->name('edit');
        Route::patch('/{ticket}', 'update')->name('update');
        Route::post('/{ticket}/status', 'changeStatus')->name('status');
```
`{ticket}` is a ticket **number** like `TKT-2026-000026`. Laravel turns it into a `Ticket` object before the
controller runs (section 5.5 shows how). `status` is the single endpoint for every status change: resolve,
close, reopen and the working statuses.

`TicketController::changeStatus`:

```php
public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket, ChangeTicketStatus $changeStatus): RedirectResponse
```
Laravel creates all three arguments. `ChangeTicketStatusRequest` validates the form **and checks permission**
before this method runs, `$ticket` comes from the URL, and `ChangeTicketStatus` is the action, created
automatically (that's called dependency injection).

```php
    $from = $ticket->status;
    $to = $request->target();
```
Remember the old status (for the message) and read the validated new status.

```php
    $changeStatus($request->user(), $ticket, $to, $request->validated('reason'));
```
Hand the work to the action: who did it, which ticket, the target, and an optional reason.

```php
    $message = match (true) {
        $to === TicketStatus::Resolved => __('Ticket resolved.'),
        $to === TicketStatus::Closed => __('Ticket closed.'),
        ! $from->isActive() && $to->isActive() => __('Ticket reopened.'),
        default => __('Status changed to :status.', ['status' => $to->label()]),
    };

    return redirect()->route('tickets.show', $ticket)->with('status', $message);
```
Pick a friendly message (`__()` makes it translatable) and go back to the ticket page with it.

That's the whole controller method: no rules, no database. The rules are in the request (permission) and the
action (the change).

### 4.2 An action: `CreateTicket`

`app/Modules/Tickets/Actions/CreateTicket.php`, the part that does the work:

```php
return DB::transaction(function () use ($actor, $data, $files, &$stored): Ticket {
```
Everything inside is **one database transaction**: either all of it is saved, or none of it.

```php
    $now = CarbonImmutable::now();
    $priority = $data['priority'] instanceof TicketPriority
        ? $data['priority']
        : TicketPriority::from($data['priority']);
```
Take the time once (so every timestamp below matches) and turn the submitted text `"high"` into the enum.

```php
    $ticket = Ticket::query()->create([
        'ticket_number' => $this->numbers->generate($now),
```
Get the next number, e.g. `TKT-2026-000043` (section 4.3 shows how it stays unique and gap-free).

```php
        'subject' => $data['subject'],
        'description' => $data['description'],
        'priority' => $priority,
        'status' => TicketStatus::Open,
        'category_id' => (int) $data['category_id'],
        'created_by' => $actor->getKey(),
        'source' => $data['source'] ?? TicketSource::Web,
        'external_reference' => $data['external_reference'] ?? null,
    ] + $this->sla->calculate($priority, $now)->toTicketAttributes());
```
The ticket's columns, plus the deadline columns (`sla_minutes`, `sla_warning_at`, `due_at`) from the SLA
calculator (section 4.4). New tickets always start `Open`.

```php
    $this->activity->record($ticket, ActivityType::TicketCreated, $actor, "Ticket {$ticket->ticket_number} created", [], [
        'status' => TicketStatus::Open->value,
        'priority' => $priority->value,
        'category_id' => $ticket->category_id,
    ]);
```
Write the first history entry, in the same transaction, so a ticket can never exist without it.

```php
    $stored = ($this->storeAttachments)($ticket, null, $actor, $files);
```
Save uploaded files to the private disk and record them. `$stored` remembers them so they can be deleted if
anything fails.

```php
    TicketCreated::dispatch($ticket, $actor);
```
Announce "a ticket was created". The event is marked to fire **only after the transaction commits**, so no
email goes out for a ticket that was rolled back.

```php
    if (config('support.assignment.auto_on_create')) {
        AutoAssignTicket::dispatch($ticket->getKey())->afterCommit();
    }

    return $ticket;
});
```
Queue the background job that picks an agent, also only after commit.

Outside the transaction, a `try/catch` deletes `$stored` files if anything threw, and then **re-throws**:
errors are never swallowed.

### 4.3 Ticket numbers: `TicketNumberGenerator::reserve`

```php
$year = ($at ?? now())->year;
```
Numbers restart every year, so first find the year.

```php
$last = DB::transaction(function () use ($year, $count): int {
    DB::table('ticket_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
```
Make sure this year's counter row exists (if two requests try at once, one insert is ignored, which is fine).

```php
    $current = (int) DB::table('ticket_sequences')
        ->where('year', $year)
        ->lockForUpdate()
        ->value('last_number');
```
Read the counter **and lock its row** (`SELECT … FOR UPDATE`). A second request reaching this line waits here
until the first one's transaction finishes. That waiting is what makes duplicates impossible.

```php
    DB::table('ticket_sequences')->where('year', $year)->update(['last_number' => $current + $count]);

    return $current + $count;
});
```
Add the count (1 for a normal ticket, a block of numbers for an import) and save it.

```php
return array_map(fn (int $number): string => $this->format($year, $number), range($last - $count + 1, $last));
```
Format each reserved number as `TKT-2026-000043`.

Because `CreateTicket` calls this **inside its own transaction**, the transaction here joins it. If the ticket
fails to save, the counter increase is undone too, so no number is wasted (no gaps).

### 4.4 Deadlines in business hours: `BusinessCalendar::addBusinessMinutes`

```php
if ($minutes < 0) {
    throw new InvalidArgumentException('Business minutes must not be negative.');
}
```
Refuse nonsense input loudly.

```php
$cursor = $this->nextOpening($start);
```
If the ticket arrives outside business hours (night, Friday, a holiday), the clock starts at the next opening.

```php
$remaining = $minutes * 60;
```
Work in seconds so a ticket created at 17:00:30 keeps its 30 seconds.

```php
for ($i = 0; $i < self::MAX_DAYS; $i++) {
    $closes = $this->window($cursor)[1];
    $available = $closes->getTimestamp() - $cursor->getTimestamp();
```
How much working time is left today, from the cursor to closing time?

```php
    if ($remaining <= $available) {
        return $cursor->addSeconds($remaining);
    }
```
If the rest of the target fits today, the deadline is today. Done.

```php
    $remaining -= $available;
    $cursor = $this->nextOpening($cursor->addDay()->startOfDay());
}
```
Otherwise use up today and jump to the next working day's opening time, then repeat.

```php
throw NoBusinessHoursConfigured::within(self::MAX_DAYS);
```
If a whole year has no working time (every day closed), fail loudly instead of looping forever.

Example: critical (120 minutes), created Thursday 17:00, hours Sunday–Thursday 09:00–18:00. Thursday has 60
minutes left → 60 remain → Friday and Saturday are closed → Sunday opens 09:00 → due **Sunday 10:00**.

`BusinessHoursSlaCalculator::calculate` calls this twice: once with 80% of the target (the warning time) and
once with the full target (the deadline). Both results are stored on the ticket in UTC.

### 4.5 Two agents, one ticket: `ClaimTicket`

```php
return DB::transaction(function () use ($ticket, $agent, $actor): bool {
    $now = CarbonImmutable::now();
    $statusBefore = Ticket::query()->whereKey($ticket->getKey())->value('status');
```
Start a transaction and remember the old status for the history entry.

```php
    $affected = Ticket::query()
        ->whereKey($ticket->getKey())
        ->whereNull('assigned_agent_id')
        ->whereIn('status', TicketStatus::activeValues())
        ->update([
            'assigned_agent_id' => $agent->getKey(),
            'assigned_at' => $now,
            'status' => self::openBecomesInProgress(),
        ]);
```
**The key line.** One SQL statement: "assign this ticket to me, *but only if nobody owns it and it's still
active*". The database runs this atomically. If two agents click Accept at the same instant, both statements
run, but only the first finds `assigned_agent_id IS NULL`; the second changes **0 rows**.

```php
    if ($affected === 0) {
        return false; // someone else won (or the ticket is no longer active)
    }
```
0 rows changed means we lost. The controller turns `false` into a 409 page: "This ticket was just taken by
another agent."

```php
    $ticket->refresh();
    self::touchAgent($agent, $now);
    $this->activity->record($ticket, ActivityType::Assigned, $actor, /* "Accepted by …" / "Auto-assigned to …" */);
    TicketAssigned::dispatch($ticket, $agent, $actor, null);
```
We won: reload the ticket, note when the agent last got work (used by auto-assignment), write history, and
announce it (the agent and customer get notified after commit).

### 4.6 Escalation exactly once: `EscalateTicket::breach`

```php
$affected = $this->guarded($ticketId)
    ->whereNull('breached_notified_at')
    ->where('due_at', '<', now())
    ->update([
        'breached_notified_at' => now(),
        'is_escalated' => true,
        'escalation_level' => DB::raw('CASE WHEN escalation_level < 1 THEN 1 ELSE escalation_level END'),
    ]);
```
The same trick as `ClaimTicket`: "mark this ticket breached, *only if it isn't marked yet and really is
overdue*". `guarded()` also requires the ticket to still be active.

```php
if ($affected === 0) {
    return false;
}
```
Already done (by an earlier scan or a retried job), so do nothing. This is why customers never get duplicate
"deadline missed" emails, however often the scheduler runs.

```php
$this->activity->record($ticket, ActivityType::SlaBreached, null, "SLA breached: was due {$due}", …);
$this->activity->record($ticket, ActivityType::Escalated, null, 'Escalated to level 1 (SLA breached)', …);
SlaBreached::dispatch($ticket);
```
History entries (actor `null` = the system), then the event that notifies the agent and every admin.

```php
if ($ticket->assigned_agent_id === null) {
    AutoAssignTicket::dispatch($ticket->id)->afterCommit();
}
```
An overdue ticket with no owner gets an auto-assignment attempt right away.

### 4.7 The ticket list: `TicketFilter::apply`

```php
$query->visibleTo($this->user);
```
**Always first:** limit the query to tickets this user may see (section 5.4). No filter below can widen it.

```php
if ($this->user->isAgent()) {
    $query->where('assigned_agent_id', $this->user->getKey());
}
```
An agent's list shows only their own tickets; unassigned ones are in the Unassigned queue page instead.

```php
$this->keyword($query);
$this->scope($query);
$this->statuses($query);
$this->priorities($query);
$this->category($query);
$this->createdRange($query);
```
Each filter is one small method that adds a `WHERE` when its value is present.

```php
if ($this->user->isAdmin()) {
    $this->assignee($query);
}

if ($this->user->isStaff()) {
    $this->customer($query);
    $this->slaStatus($query);
    $this->escalated($query);
}

$this->sort($query);
```
Staff-only filters are ignored for customers, even if someone types them into the URL. Sorting accepts only a
fixed list of columns, so nobody can inject SQL through `?sort=`.

The controller then pages the result with `DeferredPagination`: first only the ids for this page (fast, from an
index), then the full rows for just those 15.

---

## 5. Worked example: roles and permissions, every line

This is the full chain that decides "may this person do this?", from the database column to the tests.

### 5.1 The role is a column

`database/migrations/0001_01_01_000000_create_users_table.php`:

```php
$table->string('role', 20)->default('customer');
```
Every user has one role, stored as text. New sign-ups are customers by default.

```php
$table->index(['role', 'is_available', 'last_assigned_at'], 'users_role_available_last_assigned_index');
$table->index(['role', 'name']);
```
Indexes for "available agents, least recently assigned first" (auto-assignment) and "agents by name"
(dropdowns).

### 5.2 The role is an enum

`app/Enums/Role.php`:

```php
enum Role: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Customer = 'customer';
```
The only three allowed values. The `User` model casts the `role` column to this enum, so code compares
`Role::Admin` instead of the string `'admin'` (a typo becomes an error, not a silent bug).

```php
    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }
```
"Staff" means admin or agent: they get the staff screens and see internal notes.

`app/Models/User.php` adds shortcuts used everywhere:

```php
public function isAdmin(): bool { return $this->role === Role::Admin; }
public function isAgent(): bool { return $this->role === Role::Agent; }
public function isCustomer(): bool { return $this->role === Role::Customer; }
public function isStaff(): bool { return $this->role->isStaff(); }
```

**Nobody can change their own role.** `role` is not in the model's `$fillable` list, so even if someone adds
`role=admin` to a form, Laravel ignores it (and in development it throws). Roles change only through seeders
or explicit code using `forceFill`.

### 5.3 Layer 1: the role middleware on URL groups

`app/Http/Middleware/EnsureRole.php`:

```php
public function handle(Request $request, Closure $next, string ...$roles): Response
```
Runs before the controller. `...$roles` receives the roles written in the route, e.g. `role:admin,agent`
gives `['admin', 'agent']`.

```php
    $user = $request->user();

    if ($user === null) {
        throw new AuthenticationException;
    }
```
Not signed in: send to the sign-in page.

```php
    $allowed = array_map(fn (string $role) => Role::from(trim($role)), $roles);
```
Turn the route's text into enums. A typo in a route (`role:admn`) crashes immediately in tests instead of
silently letting nobody (or everybody) in.

```php
    abort_unless(in_array($user->role, $allowed, true), Response::HTTP_FORBIDDEN);

    return $next($request);
}
```
Wrong role: 403 Forbidden. Right role: continue to the controller.

`bootstrap/app.php` gives it the short name `role`:

```php
$middleware->alias(['role' => EnsureRole::class]);
```

And the module routes use it for pages that depend **only** on the role:

```php
Route::middleware(['auth', 'verified', 'role:admin'])->group(/* /admin/sla-settings, /admin/imports */);
Route::middleware(['auth', 'verified', 'role:admin,agent'])->group(/* /staff/dashboard */);
Route::get('/staff/unassigned', …)->middleware('role:admin,agent');
Route::middleware(['auth', 'role:agent'])->group(/* PATCH /profile/availability */);
```

That's all these pages need: the dashboard, queue, SLA settings, import and availability switch have no
per-record rule, just "is this person an admin / staff / agent?".

### 5.4 Layer 2: the policy for each ticket

Some rules depend on **which ticket**: "only the assigned agent may resolve it", "a customer may edit only
their own ticket while it's open". A role can't express that, so `app/Modules/Tickets/Policies/TicketPolicy.php`
answers it, given the user and the ticket.

```php
public function before(User $user, string $ability, mixed ...$arguments): ?Response
{
```
Laravel calls `before()` first, for **every** question asked of this policy.

```php
    $ticket = $arguments[0] ?? null;
    $agentAccepting = $ability === 'accept' && $user->isAgent();

    if ($ticket instanceof Ticket && ! $agentAccepting && ! $this->canSee($user, $ticket)) {
        return Response::denyAsNotFound();
    }
```
If the user may not even **see** this ticket, the answer is "404 Not Found" for any question, exactly like a
ticket number that doesn't exist. So a customer can't discover that `TKT-2026-000123` exists by trying it.
Accept is the exception for agents: losing a race must show "just taken" (409), not "not found".

```php
    return null;
}
```
`null` means "no opinion, go on to the specific rule below".

```php
public function view(User $user, Ticket $ticket): bool
{
    return $this->canSee($user, $ticket);
}
```
Opening the ticket page.

```php
public function work(User $user, Ticket $ticket): bool
{
    return $user->isAdmin() || $this->isAssignee($user, $ticket);
}
```
**The core rule:** changing priority, category or working status, resolving, and writing internal notes are
for the assigned agent or an admin.

```php
public function update(User $user, Ticket $ticket): bool
{
    return $this->work($user, $ticket)
        || ($this->owns($user, $ticket) && $ticket->status === TicketStatus::Open);
}
```
Editing subject and description: whoever may `work`, or the customer who created it, while it's still open.

```php
public function reply(User $user, Ticket $ticket): bool
{
    return $ticket->status !== TicketStatus::Closed
        && ($this->work($user, $ticket) || $this->owns($user, $ticket));
}
```
Public replies: never on a closed ticket; otherwise the people working it or the customer.

```php
public function close(User $user, Ticket $ticket): bool
{
    return $this->work($user, $ticket)
        || ($this->owns($user, $ticket) && $ticket->status === TicketStatus::Resolved);
}
```
Staff close at any time; the customer can confirm a resolved ticket by closing it.

```php
public function reopen(User $user, Ticket $ticket): bool
{
    return match ($ticket->status) {
        TicketStatus::Resolved => $this->work($user, $ticket) || ($user->isCustomer() && $this->owns($user, $ticket)),
        TicketStatus::Closed => $user->isAdmin(),
        default => false,
    };
}
```
A resolved ticket can be reopened by the customer or the people working it; a closed one only by an admin;
an active one can't be "reopened" at all.

```php
public function assign(User $user, Ticket $ticket): bool  { return $user->isAdmin() && $ticket->isActive(); }
public function accept(User $user, Ticket $ticket): bool  { return $user->isAgent() && $ticket->isActive(); }
```
Only admins pick who gets a ticket. Any agent may *try* to accept; `ClaimTicket` decides atomically whether
they won (section 4.5).

```php
public function release(User $user, Ticket $ticket): bool
{
    return $this->work($user, $ticket) && $ticket->assigned_agent_id !== null && $ticket->isActive();
}

public function viewInternalNotes(User $user, Ticket $ticket): bool
{
    return $user->isStaff();
}
```
Handing a ticket back to the queue, and the single switch for seeing internal notes.

```php
private function canSee(User $user, Ticket $ticket): bool
{
    return match (true) {
        $user->isAdmin() => true,
        $user->isAgent() => $this->isAssignee($user, $ticket)
            || ($ticket->assigned_agent_id === null && $ticket->isActive()),
        default => $this->owns($user, $ticket),
    };
}
```
**Who may see a ticket at all:** admins see everything; agents see their own tickets plus active unassigned
ones (so they can read a queue ticket before accepting); customers see the tickets they created.

```php
private function owns(User $user, Ticket $ticket): bool       { return $ticket->created_by === $user->getKey(); }
private function isAssignee(User $user, Ticket $ticket): bool { return $ticket->assigned_agent_id !== null && $ticket->assigned_agent_id === $user->getKey(); }
```
"Owner" is the customer who created it; "assignee" is the agent it's assigned to.

The policy is connected to the model in `AppServiceProvider::configureModules`:

```php
Gate::policy(Ticket::class, TicketPolicy::class);
```
(Laravel can't find it automatically because module models live outside `app/Models`.)

### 5.5 The same rule for lists: `Ticket::scopeVisibleTo`

A policy answers for **one** ticket. Lists (the ticket list, search, dashboard) need the same rule as SQL:

```php
public function scopeVisibleTo(Builder $query, User $user): void
{
    if ($user->isAdmin()) {
        return;
    }
```
Admins: no restriction.

```php
    if ($user->isAgent()) {
        $query->where(fn (Builder $visible) => $visible
            ->where('assigned_agent_id', $user->getKey())
            ->orWhere(fn (Builder $queue) => $queue
                ->whereNull('assigned_agent_id')
                ->whereIn('status', TicketStatus::activeValues())));

        return;
    }
```
Agents: `WHERE (assigned_agent_id = me OR (assigned_agent_id IS NULL AND status IN active))`, the same as
`canSee()`. The parentheses (the nested `where`) matter: without them, the `OR` would leak through other
filters.

```php
    $query->where('created_by', $user->getKey());
}
```
Customers: only tickets they created.

**`canSee()` and `scopeVisibleTo()` must always agree.** If you change one, change the other; the tests in
`tests/Feature/Authorization` will catch a mismatch.

### 5.6 Loading `{ticket}` from the URL

`AppServiceProvider::configureRouteBindings`:

```php
Route::bind('ticket', function (string $value): Ticket {
    $user = request()->user();

    return Ticket::query()
        ->where('ticket_number', $value)
        ->when($user instanceof User && $user->isCustomer(), fn ($query) => $query->visibleTo($user))
        ->firstOrFail();
});
```
Find the ticket by its number. For **customers**, search only among their own tickets, so someone else's
number simply isn't found (404) and the foreign ticket is never even loaded. For staff, the policy's `before()`
gives the same 404 (it can't be done here, or a lost Accept race would show "not found" instead of "just taken").

### 5.7 Where the policy is asked

Each action on one ticket asks the policy **once**, either in the controller:

```php
$this->authorize('view', $ticket);   // TicketController::show: 404 or 403 if not allowed
```

or in the form request, when the action has a form:

```php
// StoreReplyRequest
public function authorize(): Response
{
    $ability = $this->replyType() === ReplyType::InternalNote ? 'work' : 'reply';

    return Gate::forUser($this->user())->inspect($ability, $this->ticket());
}
```
An internal note needs `work`, a public reply needs `reply`. It returns the policy's full `Response` (not just
true/false), so a "404" answer stays a 404 instead of turning into a 403.

```php
public function replyType(): ReplyType
{
    if (! $this->user()->isStaff()) {
        return ReplyType::Public;
    }

    return ReplyType::tryFrom((string) $this->input('type')) ?? ReplyType::Public;
}
```
The reply type is **decided on the server**: a customer's reply is always public, whatever the form says.

`ChangeTicketStatusRequest` picks the ability from the target status:

```php
return match (true) {
    $to === TicketStatus::Closed => 'close',
    ! $ticket->isActive() && $to->isActive() => 'reopen',
    default => 'work',
};
```

### 5.8 Hiding buttons (cosmetic only)

Templates hide what the user can't do, using the **same** questions:

```blade
@staff … @endstaff                       {{-- staff-only parts (defined in AppServiceProvider) --}}
@if ($user->isAdmin()) … @endif          {{-- the Administration section of the sidebar --}}
@can('work', $ticket) … @endcan          {{-- the internal-note tab, status actions --}}
```
This is only for a clean screen. Every request is still checked on the server, so a hidden button that someone
re-creates by hand still gets a 403 or 404.

### 5.9 The tests that guard it

- **`tests/Feature/Authorization/TicketPolicyMatrixTest.php`:** a table of every ability × ticket state × person
  (admin, assignee, another agent, owner, another customer), with the expected answer: **A**llowed,
  **D**enied (403) or **N**ot found (404). Change a rule and the table tells you exactly what changed.
- **`tests/Feature/Security/RouteAuthorizationAuditTest.php`:** fails the build if any URL has no
  authorization at all (no role middleware, no policy call, not on a reviewed list), and if any per-ticket URL
  skips the policy.
- **`tests/Feature/Authorization/IdorTest.php`** and **`TicketAccessTest.php`:** real HTTP requests proving
  someone else's ticket is a 404 on every route, and that agents see only their own.

### 5.10 Adding a new permission rule: the steps

1. Decide: does it depend on the record (use the policy) or only the role (use `role:` middleware)?
2. Policy: add or change a method in `TicketPolicy`; if it changes *visibility*, change `canSee()` **and**
   `scopeVisibleTo()` together.
3. Ask it once: in the controller (`$this->authorize('ability', $ticket)`) or the form request (`authorize()`
   returning `Gate::forUser(...)->inspect(...)`).
4. Hide the button in the template with `@can('ability', $ticket)`.
5. Add the row to the policy matrix test, and an HTTP test for the important cases.
6. Run the tests (section 8).

---

## 6. The workflow for any task

Every feature in this project was built in the same order. Following it keeps the code consistent.

1. **Understand the rule.** Read the requirement, find the module it belongs to, and read the existing tests
   there. Write down the decision and the alternatives (section 7 is where decisions live).
2. **Database first, if needed.** Add or change the migration in the module's `database/migrations`, the
   model's `$fillable` and `casts()`, and the factory in `database/factories`.
3. **The action.** Put the work in one `Actions/` class with `DB::transaction(...)`. Record a history entry
   with `TicketActivityRecorder`. Fire an event (after commit) if someone should be notified.
4. **Permission.** Policy ability (or role middleware), asked once.
5. **Validation.** A form request in `Http/Requests` with the rules.
6. **The URL and controller.** A route in the module's `routes/web.php` and a thin controller method:
   check, validate, call the action, return.
7. **The screen.** A Blade view in the module's `resources/views`, using the shared components
   (`<x-card>`, `<x-badge>`, …).
8. **Notifications, if any.** A listener in `Notifications/Listeners` (who) and a notification class (what).
9. **Tests.** A unit test for pure logic, a feature test for the HTTP flow, rows in the policy matrix for new
   rules.
10. **Quality checks:** `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `php artisan test` on SQLite and on
    MySQL.
11. **Docs:** update the UI guide if a screen changed, and section 7 here if you made a design decision.

**Real example: "agents should see only their own tickets"** went through this workflow:

- **Rule and decision:** agents see their assigned tickets in every status, plus the unassigned queue (so they
  can still accept work); anything else is a 404. The alternative, "agents see everything", was the old
  behaviour; "only assigned, no queue" would have broken the accept flow.
- **Permission:** `canSee()` in the policy and `scopeVisibleTo()` changed together; `before()` returns 404 for
  unseen tickets except an agent's accept.
- **List:** `TicketFilter` keeps agents to `assigned_agent_id = me` and ignores the agent filter for them.
- **URL binding:** scoped for customers only (section 5.6 explains why).
- **Form requests:** return the policy `Response` so 404s survive.
- **Screen:** "My tickets" title and sidebar label for agents; the agent filter hidden.
- **Tests:** the matrix column "other agent" became 404 for others' tickets; new HTTP tests for the agent list
  and the 404s; pint, Larastan and both databases green.

---

## 7. Decisions and the alternatives we rejected

Each entry: **what we chose**, the **alternatives**, **why** ours won, and the **trade-off** we accepted.
New decisions go at the end of the matching group.

### Foundation

**Laravel 12 on PHP 8.2.**
- *Alternatives:* Laravel 11; a newer framework version needing PHP 8.3+.
- *Why:* Laravel 12 is supported for longer than 11 and still runs on the installed PHP 8.2.
- *Trade-off:* newer framework versions need a PHP upgrade first.

**Server-rendered Blade pages (with Breeze for sign-in), not a JavaScript single-page app.**
- *Alternatives:* Inertia with Vue or React; a separate SPA calling a JSON API.
- *Why:* one codebase, authorization stays on the server by default (no rules duplicated in JavaScript, no API
  tokens), and a much smaller surface to secure and test.
- *Trade-off:* full page reloads; less "app-like" interaction.

**Actions and services, not fat controllers or a repository layer.**
- *Alternatives:* logic in controllers; logic in models; repository + service layers.
- *Why:* each business task is one testable class with exactly one transaction, callable from controllers, jobs,
  commands and tests alike. A repository over Eloquent only re-wraps the query builder.
- *Trade-off:* more, smaller files.

**Feature modules with L5Modular.**
- *Alternatives:* Laravel's default layer folders (all controllers together, all models together…);
  `nwidart/laravel-modules` (a service provider, composer file and asset build per module).
- *Why:* everything about tickets is in one folder; L5Modular adds that with one provider and no per-module
  setup.
- *Trade-off:* the package is no longer actively maintained; its "factories inside modules" feature is broken on
  current Laravel, so factories stay in `database/factories` (a test enforces it). Replacing it would take about
  twenty lines in a provider.

**All business settings in `config/support.php`.**
- *Alternatives:* constants in classes; numbers scattered in code; only a database settings table.
- *Why:* one place to change behaviour, overridable per environment with `SUPPORT_*` values, easy to change in
  tests. Rules admins edit at runtime (SLA targets, hours, holidays) live in tables, with these values as
  defaults.
- *Trade-off:* changing a config value needs a deploy or an `.env` change.

**Redis for queue and cache in production, the database locally.**
- *Alternatives:* database everywhere; Redis everywhere; Amazon SQS.
- *Why:* Redis is fast and supports the locks the scheduler needs; the database version needs nothing extra, so
  a fresh clone runs with only MySQL. `predis` avoids a PHP extension.
- *Trade-off:* production must switch three settings to Redis.

**Sessions in the database; mail to the log file locally.**
- *Alternatives:* file or cookie sessions; a mail catcher like Mailpit.
- *Why:* database sessions work across several servers and don't depend on the cache; logged mail needs no
  extra service.
- *Trade-off:* a session read per request.

**Everything stored in UTC; converted to the business timezone only for display and deadline maths.**
- *Alternatives:* store local time; use the business timezone as the app timezone.
- *Why:* UTC never jumps (no daylight-saving surprises) and can't be broken by an `.env` value.
  `BusinessTime::format()` is the one place that converts for display.
- *Trade-off:* SQL written by hand must remember the times are UTC.

**Our own small design system, not the Breeze defaults or a UI kit.**
- *Alternatives:* restyle Breeze in place; a component library (Filament, Flux, Tailwind UI).
- *Why:* a few Tailwind classes and Blade components keep every page consistent with no licence or heavy
  dependency; fonts are bundled so no third-party requests are needed.
- *Trade-off:* Breeze updates to the sign-in screens must be merged by hand.

**The opcodesio log viewer as the only log screen.**
- *Alternatives:* our own error log in the database (we built one and removed it); an external service such as
  Sentry.
- *Why:* the log file already holds every error with full context; a maintained viewer gives admins search and
  download with no code to maintain.
- *Trade-off:* no grouping of repeated errors, no "resolved" state, no reference number on error pages.

### Database

**Statuses, priorities and roles as text columns cast to PHP enums.**
- *Alternatives:* MySQL `ENUM` columns; lookup tables with foreign keys.
- *Why:* adding a status is a code change, not a slow `ALTER TABLE`; values are readable in SQL; PHP gets type
  safety and behaviour (`isActive()`, `allowedTransitions()`).
- *Trade-off:* the database doesn't stop an invalid value written by raw SQL.

**Deadline columns stored on the ticket (`sla_minutes`, `sla_warning_at`, `due_at`).**
- *Alternatives:* calculate the SLA state on every page view; a separate SLA table.
- *Why:* lists, filters, the dashboard and the escalation scan use fast indexed comparisons like
  `due_at < now()`, and later rule changes don't rewrite history.
- *Trade-off:* when rules change, existing tickets keep their deadlines unless `sla:recalculate` is run.

**Replies and internal notes in one table, with a `type` column.**
- *Alternatives:* two tables.
- *Why:* one timeline query, one attachment relation, and visibility decided in one place.
- *Trade-off:* every customer-facing query must filter by type, which `repliesVisibleTo()` does.

**A purpose-built, append-only history table (`ticket_activities`).**
- *Alternatives:* the `spatie/laravel-activitylog` package; model observers writing generic diffs.
- *Why:* fast timeline by ticket, no polymorphic joins, full control over what is stored, and entries carry
  business meaning ("Reassigned from A to B") that a column diff can't.
- *Trade-off:* each action must remember to record its entry (tests check every one).

**No soft deletes; explicit foreign-key rules.**
- *Alternatives:* soft deletes; cascading deletes everywhere; no foreign keys.
- *Why:* it's an audit system and deleting tickets isn't a feature; the database itself guarantees no orphaned
  rows or silently lost history.
- *Trade-off:* removing data needs a deliberate script.

**Composite indexes shaped to the real queries, measured on 100,000 tickets.**
- *Alternatives:* one index per column; only foreign-key indexes; guessing.
- *Why:* each index matches a real `WHERE … ORDER BY` and most answer from the index alone. Measured with
  `EXPLAIN` on a 100k-ticket data set, the worst list and dashboard queries dropped from over a minute to well
  under a second (the priority sort went from 112 s to 0.1 s; the admin dashboard from 60 s to 0.6 s).
- *Trade-off:* more indexes to maintain on writes. Each one has a comment in the tickets migration saying which
  query it serves.

**One migration per table.**
- *Alternatives:* keep the history of follow-up migrations.
- *Why:* the app has never been deployed, so there's no production database to migrate step by step; one file
  per table is far easier to read.
- *Trade-off:* a local database must be rebuilt (`migrate:fresh --seed`) after such a change.

**Full-text search on MySQL, a `LIKE` fallback elsewhere.**
- *Alternatives:* `LIKE '%word%'` only (can't use an index); a search engine (Scout with Meilisearch).
- *Why:* full-text search stays fast at millions of rows with no extra service; SQLite tests still run.
- *Trade-off:* MySQL ignores very short words; replies aren't searched.

**Realistic sample data created by replaying the real actions.**
- *Alternatives:* random factory data written straight into columns; hand-written rows.
- *Why:* numbers, deadlines, history and notifications are exactly what the app would produce, so the demo
  never shows impossible data.
- *Trade-off:* seeding runs about 300 real transactions, and dates depend on the day you seed.

### Permissions and security

**One `role` column plus policies, not a permissions package.**
- *Alternatives:* `spatie/laravel-permission` (role and permission tables); a custom permission table.
- *Why:* there are three fixed roles and the real rules depend on ownership ("the assigned agent", "the
  customer who created it"), which a flat permission list can't express. Policies were needed anyway.
- *Trade-off:* a new role means a code change.

**Role middleware for role-only pages, policies for per-ticket rules, each check in one place.**
- *Alternatives:* only middleware (can't see the ticket); only policies (repetitive for role-only pages); gates
  duplicating the middleware (what we had before).
- *Why:* each rule lives exactly once: `role:` on the URL group when only the role matters, the policy when the
  ticket matters.
- *Trade-off:* fine-grained abilities (for example "may resolve but not change priority") would need `work`
  split again.

**Policy plus a query scope (`canSee` + `visibleTo`).**
- *Alternatives:* load everything, then filter with the policy; only a scope.
- *Why:* the policy protects one record; the scope protects lists, search and the dashboard at the SQL level,
  with correct page counts.
- *Trade-off:* two definitions must stay in sync (tests check both).

**404, not 403, for tickets you may not see.**
- *Alternatives:* 403 Forbidden.
- *Why:* ticket numbers are sequential; a 403 would confirm a number exists and let anyone enumerate them.
- *Trade-off:* a user who mistypes a number can't tell "missing" from "not yours" (intended).

**Agents see only their own tickets plus the unassigned queue.**
- *Alternatives:* staff see everything; agents see their own plus every unassigned ticket in their list.
- *Why:* agents shouldn't read colleagues' tickets, but must still reach the queue to accept work.
- *Trade-off:* a reassigned ticket disappears for the previous agent; a "new ticket" notification link can 404
  if a colleague took it first.

**Internal notes: added by the assigned agent or an admin, seen by staff only, decided on the server.**
- *Alternatives:* any agent may add notes; trust a hidden form field for the reply type.
- *Why:* notes stay with the people working the ticket; the server forces customers' replies to be public, so a
  tampered form can't create a note.
- *Trade-off:* other agents can't add notes to tickets they don't own.

**Mass assignment: explicit `$fillable`, roles never fillable.**
- *Alternatives:* `$guarded = []`.
- *Why:* even if a controller passes too much input, no one can make themselves an admin.
- *Trade-off:* new columns must be added to `$fillable` deliberately.

**A test that audits every route for authorization.**
- *Alternatives:* rely on code review; a middleware forcing authorization (Laravel has no reliable signal for
  that).
- *Why:* a new URL without any check can't be merged without someone adding it to a reviewed list with a reason.
- *Trade-off:* adding a route sometimes means adding a line to the test's list.

**Strict security headers with a per-request nonce; per-user rate limits; strong passwords in production.**
- *Alternatives:* no Content-Security-Policy; a CSP package; `'unsafe-inline'` scripts; one global rate limit.
- *Why:* the nonce blocks injected scripts even if an escaping bug slips through, in about 60 lines with no
  dependency. Per-user limits match how each action is abused without punishing a whole office behind one IP.
- *Trade-off:* every inline script needs the nonce (the log viewer's page is overridden for this); Alpine needs
  `'unsafe-eval'`.

### Tickets

**Ticket numbers from a locked counter row, generated inside the ticket's transaction.**
- *Alternatives:* derive from the auto-increment id (gaps, no yearly reset); `MAX(number)+1` (two requests read
  the same max); a Redis counter (lost if Redis is flushed, not part of the database transaction); random ids
  (not human-friendly).
- *Why:* unique, gap-free, readable, restarting each year.
- *Trade-off:* ticket creation waits briefly on one row lock (thousands per second is still fine).

**Status rules in the enum (`TicketStatus::allowedTransitions()`); one status endpoint.**
- *Alternatives:* `if` statements in controllers; a state-machine package; separate routes and actions for
  resolve, close and reopen (what we had before).
- *Why:* one list of legal moves, tested pair by pair; one endpoint and one action (`ChangeTicketStatus`) for
  every move, with the right permission per target.
- *Trade-off:* none significant.

**Events fire only after the transaction commits.**
- *Alternatives:* fire immediately; fire from the controller after the action returns.
- *Why:* no email or notification ever goes out about a change that was rolled back, even when actions are
  nested.
- *Trade-off:* listeners can't run inside the original transaction (they don't need to).

**Reopening restarts the deadline; a priority change recalculates from the original creation time.**
- *Alternatives:* keep the old deadline on reopen (instantly breached).
- *Why:* a reopened ticket is new work with a fair target; re-prioritising shouldn't hand out extra time.
- *Trade-off:* the history shows a recalculated deadline entry.

**Customers may choose any priority; staff can correct it.**
- *Alternatives:* cap customers at "high".
- *Why:* customers know their own urgency; the history shows who changed it.
- *Trade-off:* staff must triage over-prioritised tickets.

### Assignment

**Claiming with one conditional `UPDATE` (compare-and-set); the affected-row count decides the winner.**
- *Alternatives:* `SELECT … FOR UPDATE` then `UPDATE` (holds a lock while PHP runs, can deadlock); a `version`
  column (optimistic locking); a Redis lock.
- *Why:* one atomic statement with no gap between "check" and "write", no lock held across PHP code, no extra
  column, no Redis dependency. The loser gets a clear 409.
- *Trade-off:* none; reassignment and release use the same guard.

**Least-workload auto-assignment, used directly.**
- *Alternatives:* round-robin (ignores that one agent may be buried in critical tickets); a pluggable strategy
  switch (we had one; nothing used the second strategy).
- *Why:* workload weighted by priority, plus category match and availability, covers the requirement in one
  query.
- *Trade-off:* a different rule means editing the class.

**Lock the candidate agents during auto-assignment.**
- *Alternatives:* no lock (two jobs pick the same "least busy" agent at once); a global lock.
- *Why:* picking the least-loaded agent is a read-then-decide across many rows, which compare-and-set can't
  express. Locking in id order prevents deadlocks.
- *Trade-off:* auto-assignments for overlapping categories run one after another.

**One queued job per ticket (unique), a 5-minute retry, and "no agent available" recorded once.**
- *Alternatives:* no uniqueness (the create hook and the retry could queue the same ticket twice).
- *Why:* no duplicate work or log lines; the retry catches tickets that found nobody.
- *Trade-off:* a ticket can wait up to 5 minutes after an agent becomes available.

**Availability only stops new automatic work.**
- *Alternatives:* admins manage availability; unavailable agents lose their tickets.
- *Why:* going on leave shouldn't strand tickets already in progress.
- *Trade-off:* someone must reassign an absent agent's tickets by hand.

### SLA and escalation

**Business minutes counted day by day in our own loop, not a package.**
- *Alternatives:* plain clock time (wrong: Thursday 17:00 + 2 hours isn't Thursday 19:00); a business-hours
  package.
- *Why:* no dependency, every line explainable, and a bad configuration fails loudly instead of looping forever.
- *Trade-off:* we maintain the algorithm (it has many unit tests).

**Rules in tables (editable by admins), config as the default, one cached reader.**
- *Alternatives:* hard-coded constants; config only (needs a deploy); read the tables on every calculation.
- *Why:* admins change rules without a deploy, and calculations cost no queries on a warm cache.
- *Trade-off:* the cache must be cleared on change (an observer does it after commit).

**Warning at a fraction of the target (80%), not a fixed time before the deadline.**
- *Alternatives:* "30 minutes before due".
- *Why:* scales with priority: 30 minutes is a quarter of a 2-hour target but almost nothing of a 48-hour one.
- *Trade-off:* none significant.

**One resolver for SLA status in PHP and SQL, side by side.**
- *Alternatives:* separate PHP and SQL code (they drift); a computed database column.
- *Why:* badges, filters and the dashboard can't disagree; a test checks both on every boundary.
- *Trade-off:* the same rule is written twice, next to each other.

**A stateless scan every minute, not one delayed job per ticket.**
- *Alternatives:* schedule a delayed job at each warning and due time.
- *Why:* delayed jobs can't follow changed priorities, hours or holidays, and are lost if the queue store is
  wiped. The scan recomputes the truth from the database every run.
- *Trade-off:* needs the scheduler running every minute.

**A guarded `UPDATE` as the "exactly once" lock for escalations.**
- *Alternatives:* a cache lock; check-then-update (racy).
- *Why:* overlapping scans, several workers and retried jobs are all safe (section 4.6).
- *Trade-off:* none.

### Conversation and attachments

**Files on a private disk, streamed through a controller that asks the policy.**
- *Alternatives:* public storage with hard-to-guess names; temporary signed URLs.
- *Why:* hard-to-guess isn't authorization, and a signed URL works for anyone it's forwarded to. The controller
  applies the same rules as the ticket on every download.
- *Trade-off:* PHP streams each download (fine at these sizes).

**Allowed extensions plus a check of the real file content; random UUID file names.**
- *Alternatives:* a blacklist of dangerous extensions; only the extension; only the content type; original names.
- *Why:* blacklists always miss something; checking both catches renamed executables; UUID names prevent path
  tricks, overwrites and guessing.
- *Trade-off:* a legitimate but unusual file type must be added to the allow-list.

**Conversation rules in one action (`AddReply`).**
- *Alternatives:* logic in the controller; reopen automatically on any customer reply.
- *Why:* status, first-response time and history are changed under one lock and can never disagree.
- *Trade-off:* none.

### Notifications

**Events → queued listeners → queued notifications (mail + bell), mail on its own queue.**
- *Alternatives:* send mail inside the action; one queue for everything; mail only.
- *Why:* requests return fast; retries come from the queue; a flood of emails can't delay assignment jobs; the
  bell needs no extra service.
- *Trade-off:* a queue worker must run.

### Search, lists and dashboard

**`TicketFilter`: one small method per filter, visibility first, whitelisted sorting.**
- *Alternatives:* query logic in the controller; a generic filter package.
- *Why:* easy to test and extend, and no filter can bypass visibility or inject SQL through sorting.
- *Trade-off:* each new filter is a few lines of code.

**Page numbers with a total for the ticket list; ids first, then rows (deferred join).**
- *Alternatives:* "next page" only; cursor pagination (no total, no page numbers).
- *Why:* people expect page numbers in a support desk; fetching ids first keeps it fast at 100,000 tickets.
- *Trade-off:* two queries per page instead of one.

**Dashboard counts in a few aggregate queries, cached for 30 seconds.**
- *Alternatives:* one query per card; no cache.
- *Why:* one pass over the table instead of eight round trips, and refresh storms don't hit the database.
- *Trade-off:* numbers can be up to 30 seconds old.

### CSV import

**A queued, resumable job that streams the file and inserts in chunks of 500.**
- *Alternatives:* process in the web request (times out); load the whole file into memory; a spreadsheet package;
  insert one row at a time; parallel batches.
- *Why:* memory stays flat at any file size; bulk inserts are orders of magnitude faster; each chunk commits a
  checkpoint so a crash resumes; one sequential job avoids fighting over ticket numbers.
- *Trade-off:* one worker processes a file; very large imports take minutes.

**Uniqueness guaranteed by a database index, with a pre-check for friendly messages.**
- *Alternatives:* pre-check only (racy); index only (no row-level messages).
- *Why:* the index is the guarantee; the pre-check gives "row 42: duplicate reference".
- *Trade-off:* none.

### Tests and quality

**Strict Eloquent outside production.**
- *Alternatives:* manual review of queries; a debug bar.
- *Why:* an N+1 query or a write to a non-fillable field throws during development and tests.
- *Trade-off:* production only logs, never breaks a page.

**MySQL for tests when available, SQLite otherwise; MySQL 8 in CI.**
- *Alternatives:* SQLite only (misses MySQL behaviour); MySQL only (no zero-setup tests).
- *Why:* a fresh clone tests immediately, and CI proves the real database engine.
- *Trade-off:* two databases to keep green.

**Test depth follows risk; a deterministic test for races; one frozen clock.**
- *Alternatives:* even coverage everywhere; only timing-based race tests; real time in tests.
- *Why:* a leaked internal note or a wrong deadline matters more than a button; deterministic tests never pass
  by luck; a frozen clock makes failures reproducible on any day.
- *Trade-off:* no browser or screenshot tests.

**Larastan level 5, with findings fixed rather than ignored.**
- *Alternatives:* level 8 (mostly noise for Laravel); a baseline of known errors; no static analysis.
- *Why:* a baseline hides exactly the findings worth reading.
- *Trade-off:* higher levels aren't enforced.

---

## 8. Tests and quality checks

```bash
php artisan test                           # MySQL support_desk_testing if reachable, else SQLite in memory
DB_TEST_CONNECTION=sqlite php artisan test # force SQLite (no setup, about 30 s)
php artisan test tests/Feature/Sla         # one area
php artisan test --filter="internal note"  # by name
vendor/bin/pint                            # fix code style
vendor/bin/phpstan analyse                 # static analysis
```

- `tests/Feature/<Area>` matches the modules (Tickets, Assignment, Conversation, Sla, Notifications, Search,
  Dashboard, Import, Activity, Logs) plus Authorization, Security, Database, Bootstrap and EndToEnd.
- `tests/Unit` holds pure logic (calendar maths, enums, CSV reader) and `ArchitectureTest.php` (the layer
  rules).
- `tests/Concurrency` races 20 real processes for one ticket (MySQL only).
- Every test runs at a frozen time (Mon 2026-11-09 10:00 Dhaka, `tests/TestCase.php`); mail, storage and outside
  HTTP are faked.
- CI (`.github/workflows/tests.yml`) runs Pint, Larastan and every test on MySQL 8 on every push.

---

## 9. I want to change …

| I want to … | Go to |
|---|---|
| change deadlines, business hours, holidays | the admin screen `/admin/sla-settings`; defaults in `config/support.php` |
| change the timezone, upload limits, auto-close days | `config/support.php` (each value has a `SUPPORT_*` env variable) |
| add a field to tickets | the tickets migration, `$fillable`/`casts()` in `Models/Ticket.php`, `StoreTicketRequest`/`UpdateTicketRequest`, `resources/views/partials/form.blade.php`, `database/factories/TicketFactory.php` |
| allow a new status move | `Tickets/Enums/TicketStatus.php` → `allowedTransitions()` |
| change who can do something to a ticket | `Tickets/Policies/TicketPolicy.php` (section 5.10) |
| change which tickets someone can see | `TicketPolicy::canSee()` **and** `Ticket::scopeVisibleTo()`, plus the agent rule in `TicketFilter::apply()` |
| guard a page by role | `role:` middleware in the module's `routes/web.php` |
| change who receives an email | the matching `Notifications/Listeners/Notify*.php` |
| change an email's text | `Notifications/Notifications/*Notification.php` and `Notifications/resources/views/mail/ticket.blade.php` |
| add a history entry type | a case in `Activity/Enums/ActivityType.php` (label and customer visibility), then record it in the action |
| change the auto-assignment rule | `Assignment/Services/LeastWorkloadStrategy.php`; weights in `config/support.php` |
| change the ticket list or filters | `Tickets/Filters/TicketFilter.php`, `Tickets/resources/views/index.blade.php`, `partials/filter-bar.blade.php` |
| change dashboard numbers | `Dashboard/Services/DashboardStatsService.php` and its view |
| change the sidebar | `resources/views/layouts/navigation.blade.php` |
| change colours or button styles | `tailwind.config.js` and `resources/css/app.css`, then `npm run build` |
| change what runs on a schedule | `routes/console.php` |
| change the sample data | `database/seeders/UserSeeder.php` and `database/seeders/data/tickets.php`, then `php artisan migrate:fresh --seed` |
| add a new module | a folder in `app/Modules` with the layout from section 3; add its migrations folder to `phpstan.neon` and map any new policy in `AppServiceProvider::configureModules` |

---

## 10. Known limitations

- The deadline keeps counting while a ticket waits for the customer.
- One business timezone and one calendar for everyone; only a resolution deadline (no first-response deadline).
- Search covers subject and description, not replies.
- No user-management screen: staff accounts come from the seeder.
- Nobody is emailed or paged about errors; admins read the logs at `/admin/log-viewer`.
- Uploads are type-checked but not virus-scanned.
- No JSON API for tickets.
