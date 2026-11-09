<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Models\Ticket;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->otherCustomer = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();

    $this->ticket = Ticket::factory()->for($this->owner, 'creator')->assigned($this->agent)->inProgress()->create();
});

it('returns 404, not 403, when a customer opens another customer\'s ticket', function () {
    $this->actingAs($this->otherCustomer)
        ->get(route('tickets.show', $this->ticket))
        ->assertNotFound();
});

it('returns the same 404 for a ticket number that does not exist', function () {
    $this->actingAs($this->otherCustomer)
        ->get('/tickets/TKT-2026-999999')
        ->assertNotFound();
});

it('shows a ticket to its owner, its assigned agent and admins', function (string $who) {
    $this->actingAs($this->{$who})
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertSee($this->ticket->ticket_number);
})->with(['owner', 'agent', 'admin']);

it('hides internal notes and the activity log from the customer', function () {
    TicketReply::factory()->for($this->ticket)->create(['user_id' => $this->owner->id, 'body' => 'Customer public reply']);
    TicketReply::factory()->for($this->ticket)->internalNote()->create(['user_id' => $this->agent->id, 'body' => 'Secret staff note']);
    TicketActivity::factory()->for($this->ticket)->create(['type' => ActivityType::Escalated, 'description' => 'Escalated to tier two']);

    $this->actingAs($this->owner)
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertSee('Customer public reply')
        ->assertDontSee('Secret staff note')
        ->assertDontSee('Escalated to tier two');

    $this->actingAs($this->agent)
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertSee('Secret staff note')
        ->assertSee('Escalated to tier two');
});

it('scopes the ticket list to the customer\'s own tickets', function () {
    $foreign = Ticket::factory()->for($this->otherCustomer, 'creator')->create();

    $this->actingAs($this->owner)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSee($this->ticket->ticket_number)
        ->assertDontSee($foreign->ticket_number);

    $this->actingAs($this->admin)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSee($this->ticket->ticket_number)
        ->assertSee($foreign->ticket_number);
});

it('returns 404 when an agent opens a ticket assigned to another agent', function () {
    $otherAgent = User::factory()->agent()->create();

    $this->actingAs($otherAgent)->get(route('tickets.show', $this->ticket))->assertNotFound();
    $this->actingAs($otherAgent)->post(route('tickets.replies.store', $this->ticket), ['body' => 'Hi'])->assertNotFound();
});

it('lets an agent open an active unassigned ticket from the queue, but not a finished one', function () {
    $queued = Ticket::factory()->unassigned()->create();
    $finishedUnassigned = Ticket::factory()->resolved()->create(['assigned_agent_id' => null]);

    $this->actingAs($this->agent)->get(route('tickets.show', $queued))->assertOk();
    $this->actingAs($this->agent)->get(route('tickets.show', $finishedUnassigned))->assertNotFound();
});

it('lists only the agent\'s own tickets, in every status', function () {
    $resolved = Ticket::factory()->assigned($this->agent)->resolved()->create();
    $closed = Ticket::factory()->assigned($this->agent)->closed()->create();
    $someoneElses = Ticket::factory()->inProgress()->create();
    $unassigned = Ticket::factory()->unassigned()->create();

    $this->actingAs($this->agent)
        ->get(route('tickets.index', ['scope' => 'all']))
        ->assertOk()
        ->assertSee($this->ticket->ticket_number)
        ->assertSee($resolved->ticket_number)
        ->assertSee($closed->ticket_number)
        ->assertDontSee($someoneElses->ticket_number)
        ->assertDontSee($unassigned->ticket_number) // unassigned tickets are in the queue, not the list
        ->assertSee('My tickets');

    // Even with an explicit agent filter, an agent never lists someone else's tickets.
    $this->actingAs($this->agent)
        ->get(route('tickets.index', ['scope' => 'all', 'assigned_agent_id' => $someoneElses->assigned_agent_id]))
        ->assertDontSee($someoneElses->ticket_number);
});

it('sends guests to the login page', function () {
    $this->get(route('tickets.show', $this->ticket))->assertRedirect(route('login'));
    $this->get(route('tickets.index'))->assertRedirect(route('login'));
});

it('lands staff on the staff dashboard and customers on their tickets', function () {
    $this->actingAs($this->agent)->get(route('dashboard'))->assertRedirect(route('staff.dashboard'));
    $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('tickets.index'));
});
