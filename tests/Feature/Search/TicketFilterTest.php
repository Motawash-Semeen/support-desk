<?php

use App\Models\User;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\TicketFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC')); // Sunday 12:00 Dhaka

    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create();
    $this->customer = User::factory()->create(['name' => 'Cara Customer']);
    $this->otherCustomer = User::factory()->create();
});

/** Ids matched by the filter for a user, sorted. */
function filtered(User $user, array $filters): array
{
    return (new TicketFilter($user, $filters))->query()->pluck('tickets.id')->sort()->values()->all();
}

function ids(Ticket ...$tickets): array
{
    return collect($tickets)->pluck('id')->sort()->values()->all();
}

/* Keyword ------------------------------------------------------------------ */

it('finds a ticket by exact number, case-insensitively, via the unique index', function () {
    $target = Ticket::factory()->create();
    Ticket::factory()->count(3)->create();

    expect(filtered($this->admin, ['q' => strtolower($target->ticket_number)]))->toBe(ids($target));

    $sql = (new TicketFilter($this->admin, ['q' => $target->ticket_number]))->query()->toSql();
    expect(strtolower(str_replace(['`', '"'], '', $sql)))->toContain('ticket_number = ?')->not->toContain('match');
});

it('searches subject and description by keyword', function () {
    $this->commitTestTransaction(); // InnoDB FULLTEXT only indexes committed rows
    $subject = Ticket::factory()->create(['subject' => 'Printer jammed on floor three', 'description' => 'Paper stuck']);
    $body = Ticket::factory()->create(['subject' => 'Office issue', 'description' => 'The big printer smells']);
    Ticket::factory()->create(['subject' => 'Password reset', 'description' => 'Locked out']);

    expect(filtered($this->admin, ['q' => 'printer', 'scope' => null]))->toBe(ids($subject, $body));
});

it('uses MATCH ... AGAINST in boolean mode with a sanitised, bound expression on MySQL', function () {
    $query = (new TicketFilter($this->admin, ['q' => 'printer -jam "paper" @me']))->query();

    expect(strtolower($query->toSql()))->toContain('match (`subject`, `description`) against (? in boolean mode)')
        ->and($query->getBindings())->toContain('+printer* +jam* +paper*');
})->skip(fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'FULLTEXT exists only on MySQL/MariaDB.');

it('treats SQL and LIKE metacharacters in keywords as plain text', function () {
    Ticket::factory()->create(['subject' => 'Normal ticket']);

    expect(filtered($this->admin, ['q' => "' OR 1=1 --"]))->toBe([])
        ->and(filtered($this->admin, ['q' => '%']))->toBe([]);
});

/* Field filters ------------------------------------------------------------ */

it('filters by several statuses', function () {
    $open = Ticket::factory()->create();
    $waiting = Ticket::factory()->create(['status' => TicketStatus::WaitingForCustomer]);
    Ticket::factory()->inProgress()->create();
    $resolved = Ticket::factory()->resolved()->create();

    expect(filtered($this->admin, ['status' => ['open', 'waiting_for_customer']]))->toBe(ids($open, $waiting))
        ->and(filtered($this->admin, ['status' => ['resolved']]))->toBe(ids($resolved)); // explicit status lifts the default "active" scope
});

it('filters by several priorities and by category', function () {
    $billing = Category::factory()->create();
    $critical = Ticket::factory()->priority(TicketPriority::Critical)->for($billing)->create();
    $high = Ticket::factory()->priority(TicketPriority::High)->create();
    Ticket::factory()->priority(TicketPriority::Low)->create();

    expect(filtered($this->admin, ['priority' => ['critical', 'high']]))->toBe(ids($critical, $high))
        ->and(filtered($this->admin, ['category_id' => $billing->id]))->toBe(ids($critical));
});

it('filters by assignee, including unassigned', function () {
    $mine = Ticket::factory()->assigned($this->agent)->create();
    Ticket::factory()->assigned()->create();
    $nobody = Ticket::factory()->create();

    expect(filtered($this->admin, ['assigned_agent_id' => (string) $this->agent->id]))->toBe(ids($mine))
        ->and(filtered($this->admin, ['assigned_agent_id' => 'unassigned']))->toBe(ids($nobody));
});

it('filters by customer id', function () {
    $hers = Ticket::factory()->for($this->customer, 'creator')->create();
    Ticket::factory()->for($this->otherCustomer, 'creator')->create();

    expect(filtered($this->admin, ['customer' => $this->customer->id]))->toBe(ids($hers));
});

it('interprets the created date range as business-timezone days', function () {
    // 2026-11-07 20:00 UTC is already 2026-11-08 02:00 in Dhaka (UTC+6).
    $lateSaturdayUtc = Ticket::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-07 20:00:00', 'UTC')]);
    $earlySaturdayUtc = Ticket::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-07 10:00:00', 'UTC')]);

    expect(filtered($this->admin, ['created_from' => '2026-11-08']))->toBe(ids($lateSaturdayUtc))
        ->and(filtered($this->admin, ['created_to' => '2026-11-07']))->toBe(ids($earlySaturdayUtc))
        ->and(filtered($this->admin, ['created_from' => '2026-11-07', 'created_to' => '2026-11-08']))->toBe(ids($lateSaturdayUtc, $earlySaturdayUtc));
});

it('filters by SLA status using the shared SQL scopes', function () {
    $within = Ticket::factory()->create(['due_at' => now()->addDay(), 'sla_warning_at' => now()->addHours(12)]);
    $approaching = Ticket::factory()->approaching()->create();
    $breached = Ticket::factory()->inProgress()->breached()->create();

    expect(filtered($this->admin, ['sla_status' => 'within_sla']))->toBe(ids($within))
        ->and(filtered($this->admin, ['sla_status' => 'approaching']))->toBe(ids($approaching))
        ->and(filtered($this->admin, ['sla_status' => 'breached']))->toBe(ids($breached));
});

it('filters escalated tickets and splits active from closed', function () {
    $escalated = Ticket::factory()->inProgress()->escalated()->create();
    $active = Ticket::factory()->create();
    $closed = Ticket::factory()->closed()->create();

    expect(filtered($this->admin, ['escalated' => '1']))->toBe(ids($escalated))
        ->and(filtered($this->admin, []))->toBe(ids($escalated, $active)) // default: active
        ->and(filtered($this->admin, ['scope' => 'closed']))->toBe(ids($closed))
        ->and(filtered($this->admin, ['scope' => 'all']))->toBe(ids($escalated, $active, $closed));
});

it('renders the filter bar with applied filters as removable chips and scope-specific statuses', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('tickets.index', ['scope' => 'active', 'priority' => ['high'], 'status' => ['in_progress']]))
        ->assertOk()
        ->assertSee('Applied:')
        ->assertSee(route('tickets.index', ['scope' => 'active', 'status' => ['in_progress']])) // remove "High"
        ->assertSee('Soonest first');

    // The Active tab offers only active statuses; the All tab keeps search and filters.
    expect($response->getContent())->not->toContain('value="closed"')
        ->toContain(e(route('tickets.index', ['priority' => ['high'], 'scope' => 'all'])));
});

it('combines filters', function () {
    $billing = Category::factory()->create();
    $match = Ticket::factory()->for($billing)->inProgress()->assigned($this->agent)->priority(TicketPriority::High)->create();
    Ticket::factory()->for($billing)->inProgress()->assigned($this->agent)->priority(TicketPriority::Low)->create(); // wrong priority
    Ticket::factory()->for($billing)->inProgress()->assigned()->priority(TicketPriority::High)->create();             // wrong agent
    Ticket::factory()->inProgress()->assigned($this->agent)->priority(TicketPriority::High)->create();                // wrong category

    expect(filtered($this->admin, [
        'status' => ['in_progress'],
        'priority' => ['high', 'critical'],
        'category_id' => $billing->id,
        'assigned_agent_id' => (string) $this->agent->id,
    ]))->toBe(ids($match));
});

/* Visibility ----------------------------------------------------------------- */

it('ignores staff-only filters from customers and keeps them inside their own tickets', function () {
    $own = Ticket::factory()->for($this->customer, 'creator')->assigned($this->agent)->create();
    $foreign = Ticket::factory()->for($this->otherCustomer, 'creator')->assigned($this->agent)->create();

    expect(filtered($this->customer, ['assigned_agent_id' => (string) $this->agent->id]))->toBe(ids($own))
        ->and(filtered($this->customer, ['customer' => $this->otherCustomer->id]))->toBe(ids($own))
        ->and(filtered($this->customer, ['q' => $foreign->ticket_number]))->toBe([]);

    $this->actingAs($this->customer)
        ->get(route('tickets.index', ['assigned_agent_id' => $this->agent->id, 'customer' => $this->otherCustomer->id]))
        ->assertOk()
        ->assertSee($own->ticket_number)
        ->assertDontSee($foreign->ticket_number)
        ->assertDontSee('name="assigned_agent_id"', false)
        ->assertDontSee('name="sla_status"', false);
});

/* Sorting -------------------------------------------------------------------- */

it('sorts staff active lists by due date and customers by newest first', function () {
    $dueLater = Ticket::factory()->for($this->customer, 'creator')->create(['due_at' => now()->addDays(2), 'created_at' => now()->subHour()]);
    $dueSooner = Ticket::factory()->for($this->customer, 'creator')->create(['due_at' => now()->addHour(), 'created_at' => now()->subDay()]);

    $staffOrder = (new TicketFilter($this->admin, []))->query()->pluck('tickets.id')->all();
    $customerOrder = (new TicketFilter($this->customer, []))->query()->pluck('tickets.id')->all();

    expect($staffOrder)->toBe([$dueSooner->id, $dueLater->id])
        ->and($customerOrder)->toBe([$dueLater->id, $dueSooner->id]);
});

it('sorts by priority weight from the whitelist', function () {
    $low = Ticket::factory()->priority(TicketPriority::Low)->create();
    $critical = Ticket::factory()->priority(TicketPriority::Critical)->create();
    $medium = Ticket::factory()->priority(TicketPriority::Medium)->create();

    $order = (new TicketFilter($this->admin, ['sort' => 'priority', 'direction' => 'desc']))->query()->pluck('tickets.id')->all();

    expect($order)->toBe([$critical->id, $medium->id, $low->id]);
});

it('rejects anything outside the whitelists', function (array $params, string $field) {
    $this->actingAs($this->admin)->get(route('tickets.index', $params))->assertSessionHasErrors($field);
})->with([
    'sort column' => [['sort' => 'password'], 'sort'],
    'sort injection' => [['sort' => 'id; DROP TABLE tickets'], 'sort'],
    'direction' => [['direction' => 'sideways'], 'direction'],
    'status' => [['status' => ['deleted']], 'status.0'],
    'sla status' => [['sla_status' => 'met'], 'sla_status'],
    'assignee' => [['assigned_agent_id' => 'everyone'], 'assigned_agent_id'],
    'date format' => [['created_from' => '08/11/2026'], 'created_from'],
    'reversed range' => [['created_from' => '2026-11-08', 'created_to' => '2026-11-01'], 'created_to'],
]);

/* Page --------------------------------------------------------------------------- */

it('renders the filter bar, the result count and shareable pagination links', function () {
    Ticket::factory()->count(17)->priority(TicketPriority::High)->create();

    $this->actingAs($this->admin)
        ->get(route('tickets.index', ['priority' => ['high']]))
        ->assertOk()
        ->assertSee('17 tickets')
        ->assertSee('name="q"', false)
        ->assertSee('priority%5B0%5D=high', false) // withQueryString keeps the filter on page links
        ->assertSee('page=2', false);
});

it('shows a filtered empty state', function () {
    $this->actingAs($this->admin)
        ->get(route('tickets.index', ['q' => 'TKT-2026-424242']))
        ->assertOk()
        ->assertSee('No tickets match these filters.');
});

it('runs the same number of queries for 5 or 50 rows', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('tickets.index', ['scope' => 'active']))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    Ticket::factory()->count(5)->assigned()->create();
    $few = $count();

    Ticket::factory()->count(45)->assigned()->create();
    $many = $count();

    expect($many)->toBe($few);
});

/* Customer autocomplete --------------------------------------------------------- */

it('autocompletes customers by name or email prefix for staff only', function () {
    // Pin the random fixtures so only the intended rows can match "car".
    $this->customer->forceFill(['email' => 'cara@example.com'])->save();
    $this->otherCustomer->forceFill(['name' => 'Olive Other', 'email' => 'olive@example.net'])->save();
    User::factory()->create(['name' => 'Carl Other', 'email' => 'carl@example.org']);
    User::factory()->agent()->create(['name' => 'Carlos Agent']); // not a customer

    $this->actingAs($this->agent)
        ->getJson(route('staff.customers.search', ['q' => 'car']))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonFragment(['name' => 'Cara Customer'])
        ->assertJsonMissing(['name' => 'Carlos Agent']);

    $this->actingAs($this->agent)->getJson(route('staff.customers.search', ['q' => 'carl@']))->assertJsonCount(1);
    $this->actingAs($this->agent)->getJson(route('staff.customers.search', ['q' => 'c']))->assertUnprocessable();
    $this->actingAs($this->customer)->getJson(route('staff.customers.search', ['q' => 'car']))->assertForbidden();
});
