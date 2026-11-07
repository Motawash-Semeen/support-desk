<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Sla\Services\SlaCalculator;
use App\Modules\Sla\Services\SlaResult;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Services\TicketNumberGenerator;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

beforeEach(function () {
    // These tests cover ticket CRUD; auto-assignment has its own tests.
    config(['support.assignment.auto_on_create' => false]);

    $this->customer = User::factory()->create();
    $this->otherCustomer = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->otherAgent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
    $this->category = Category::factory()->create();
    $this->payload = [
        'subject' => 'VPN drops every hour',
        'description' => 'Since Monday the VPN disconnects every 60 minutes.',
        'priority' => 'medium',
        'category_id' => $this->category->id,
    ];
});

/* Create & view ------------------------------------------------------ */

it('lets every role open the create form', function (string $who) {
    $this->actingAs($this->{$who})->get(route('tickets.create'))->assertOk()->assertSee($this->category->name);
})->with(['customer', 'agent', 'admin']);

it('creates a ticket and redirects to it', function (string $who) {
    $response = $this->actingAs($this->{$who})->post(route('tickets.store'), $this->payload);

    $ticket = Ticket::query()->sole();

    $response->assertRedirect(route('tickets.show', $ticket))->assertSessionHas('status');
    expect($ticket->ticket_number)->toBe(sprintf('TKT-%d-000001', now()->year))
        ->and($ticket->created_by)->toBe($this->{$who}->id)
        ->and($ticket->status)->toBe(TicketStatus::Open);

    $this->actingAs($this->{$who})->get(route('tickets.show', $ticket))->assertOk()->assertSee('VPN drops every hour');
})->with(['customer', 'agent', 'admin']);

it('validates the ticket form', function (array $override, string $field) {
    $this->actingAs($this->customer)
        ->post(route('tickets.store'), [...$this->payload, ...$override])
        ->assertSessionHasErrors($field);

    expect(Ticket::count())->toBe(0);
})->with([
    'missing subject' => [['subject' => ''], 'subject'],
    'subject too long' => [['subject' => str_repeat('a', 256)], 'subject'],
    'missing description' => [['description' => ''], 'description'],
    'description too long' => [['description' => str_repeat('a', 10_001)], 'description'],
    'unknown priority' => [['priority' => 'urgent'], 'priority'],
    'unknown category' => [['category_id' => 999_999], 'category_id'],
]);

it('rejects an inactive category', function () {
    $inactive = Category::factory()->inactive()->create();

    $this->actingAs($this->customer)
        ->post(route('tickets.store'), [...$this->payload, 'category_id' => $inactive->id])
        ->assertSessionHasErrors('category_id');
});

it('lets customers choose any priority, including critical', function () {
    $this->actingAs($this->customer)->post(route('tickets.store'), [...$this->payload, 'priority' => 'critical']);

    expect(Ticket::query()->sole()->priority)->toBe(TicketPriority::Critical);
});

it('shows a friendly message instead of a 500 when saving fails', function () {
    // Fail mid-transaction (after the number was issued) with a database error.
    $this->app->instance(SlaCalculator::class, new class implements SlaCalculator
    {
        public function calculate(TicketPriority $priority, CarbonInterface $from): SlaResult
        {
            throw new QueryException('mysql', 'select * from sla_policies', [], new Exception('Deadlock found'));
        }
    });

    $this->actingAs($this->customer)
        ->from(route('tickets.create'))
        ->post(route('tickets.store'), $this->payload)
        ->assertRedirect(route('tickets.create'))
        ->assertSessionHasErrors('general')
        ->assertSessionHasInput('subject', 'VPN drops every hour');

    expect(Ticket::count())->toBe(0)
        ->and(app(TicketNumberGenerator::class)->generate())->toEndWith('-000001'); // number was rolled back
});

/* Index tabs --------------------------------------------------------- */

it('splits the list into active and resolved/closed tabs', function () {
    $active = Ticket::factory()->for($this->customer, 'creator')->create();
    $done = Ticket::factory()->for($this->customer, 'creator')->closed()->create();

    $this->actingAs($this->customer)->get(route('tickets.index'))
        ->assertOk()->assertSee($active->ticket_number)->assertDontSee($done->ticket_number);

    $this->actingAs($this->customer)->get(route('tickets.index', ['scope' => 'closed']))
        ->assertOk()->assertSee($done->ticket_number)->assertDontSee($active->ticket_number);

    $this->actingAs($this->customer)->get(route('tickets.index', ['scope' => 'everything']))
        ->assertSessionHasErrors('scope');
});

/* Update -------------------------------------------------------------- */

it('lets the customer edit subject and description while the ticket is open', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->create();

    $this->actingAs($this->customer)->get(route('tickets.edit', $ticket))->assertOk()->assertDontSee('name="priority"', false);

    $this->actingAs($this->customer)
        ->patch(route('tickets.update', $ticket), ['subject' => 'Updated subject', 'description' => 'More detail'])
        ->assertRedirect(route('tickets.show', $ticket));

    expect($ticket->fresh()->subject)->toBe('Updated subject');
});

it('prohibits customers from changing priority or category', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->priority(TicketPriority::Low)->create();

    $this->actingAs($this->customer)
        ->patch(route('tickets.update', $ticket), ['subject' => 'x', 'priority' => 'critical', 'category_id' => $this->category->id])
        ->assertSessionHasErrors(['priority', 'category_id']);

    expect($ticket->fresh()->priority)->toBe(TicketPriority::Low);
});

it('blocks customer edits once the ticket is being worked on', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->create();

    $this->actingAs($this->customer)->patch(route('tickets.update', $ticket), ['subject' => 'x'])->assertForbidden();
});

it('lets the assigned agent change priority and logs it', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->assigned($this->agent)->priority(TicketPriority::Low)->create();

    $this->actingAs($this->agent)
        ->patch(route('tickets.update', $ticket), ['priority' => 'high'])
        ->assertRedirect(route('tickets.show', $ticket));

    expect($ticket->fresh()->priority)->toBe(TicketPriority::High)
        ->and($ticket->activities()->where('type', ActivityType::PriorityChanged)->sole()->description)->toBe('Priority changed from Low to High')
        ->and($ticket->activities()->where('type', ActivityType::SlaRecalculated)->exists())->toBeTrue();
});

it('forbids an agent who is not assigned from editing', function () {
    $ticket = Ticket::factory()->assigned($this->agent)->create();

    $this->actingAs($this->otherAgent)->patch(route('tickets.update', $ticket), ['subject' => 'x'])->assertForbidden();
});

it('returns 404 for every ticket route when a customer targets someone else\'s ticket', function (string $method, string $route, array $body) {
    $ticket = Ticket::factory()->for($this->otherCustomer, 'creator')->resolved()->create();

    $this->actingAs($this->customer)->{$method}(route($route, $ticket), $body)->assertNotFound();
})->with([
    ['get', 'tickets.show', []],
    ['get', 'tickets.edit', []],
    ['patch', 'tickets.update', ['subject' => 'x']],
    ['post', 'tickets.resolve', []],
    ['post', 'tickets.close', []],
    ['post', 'tickets.reopen', []],
    ['post', 'tickets.status', ['status' => 'in_progress']],
]);

/* Status actions ------------------------------------------------------ */

it('lets the assigned agent resolve, the customer close, and the customer reopen', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->assigned($this->agent)->create();

    $this->actingAs($this->agent)->post(route('tickets.resolve', $ticket), ['reason' => 'Fixed'])->assertRedirect(route('tickets.show', $ticket));
    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved);

    $this->actingAs($this->customer)->post(route('tickets.reopen', $ticket), ['reason' => 'Not fixed'])->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);

    $this->actingAs($this->agent)->post(route('tickets.resolve', $ticket));
    $this->actingAs($this->customer)->post(route('tickets.close', $ticket))->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::Closed);
});

it('forbids customers from resolving their own ticket', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->create();

    $this->actingAs($this->customer)->post(route('tickets.resolve', $ticket))->assertForbidden();
});

it('renders an illegal transition as a validation error, not a 500', function () {
    $ticket = Ticket::factory()->inProgress()->assigned($this->agent)->create();

    $this->actingAs($this->agent)
        ->from(route('tickets.show', $ticket))
        ->post(route('tickets.close', $ticket))
        ->assertRedirect(route('tickets.show', $ticket))
        ->assertSessionHasErrors(['status' => 'A ticket cannot move from In progress to Closed.']);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);
});

it('returns 422 JSON for an illegal transition when JSON is expected', function () {
    $ticket = Ticket::factory()->inProgress()->assigned($this->agent)->create();

    $this->actingAs($this->agent)
        ->postJson(route('tickets.close', $ticket))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('changes between working statuses through the status endpoint', function () {
    $ticket = Ticket::factory()->inProgress()->assigned($this->agent)->create();

    $this->actingAs($this->agent)
        ->post(route('tickets.status', $ticket), ['status' => 'waiting_for_customer'])
        ->assertRedirect(route('tickets.show', $ticket));

    expect($ticket->fresh()->status)->toBe(TicketStatus::WaitingForCustomer);
});

it('requires the ability that matches the target status on the status endpoint', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->resolved()->assigned($this->agent)->create();

    // Customer may reopen a resolved ticket via the generic endpoint ...
    $this->actingAs($this->customer)->post(route('tickets.status', $ticket), ['status' => 'in_progress'])->assertRedirect();
    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);

    // ... but may not use it to move between working statuses.
    $this->actingAs($this->customer)->post(route('tickets.status', $ticket), ['status' => 'waiting_for_customer'])->assertForbidden();
});

it('lets only an admin reopen a closed ticket over HTTP', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->closed()->assigned($this->agent)->create();

    $this->actingAs($this->customer)->post(route('tickets.reopen', $ticket))->assertForbidden();
    $this->actingAs($this->agent)->post(route('tickets.reopen', $ticket))->assertForbidden();
    $this->actingAs($this->admin)->post(route('tickets.reopen', $ticket))->assertRedirect();

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);
});

it('shows only the actions the viewer may take', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->assigned($this->agent)->create();

    $this->actingAs($this->agent)->get(route('tickets.show', $ticket))
        ->assertSee(route('tickets.resolve', $ticket))
        ->assertSee(route('tickets.status', $ticket))
        ->assertDontSee(route('tickets.close', $ticket));

    $this->actingAs($this->customer)->get(route('tickets.show', $ticket))
        ->assertDontSee(route('tickets.resolve', $ticket))
        ->assertDontSee(route('tickets.status', $ticket));
});
