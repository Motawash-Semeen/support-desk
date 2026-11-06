<?php

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketReply;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->otherCustomer = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();

    $this->ticket = Ticket::factory()->for($this->owner, 'creator')->inProgress()->create();
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

it('shows a ticket to its owner, any agent and admins', function (string $who) {
    $this->actingAs($this->{$who})
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertSee($this->ticket->ticket_number);
})->with(['owner', 'agent', 'admin']);

it('hides internal notes and the activity log from the customer', function () {
    TicketReply::factory()->for($this->ticket)->create(['user_id' => $this->owner->id, 'body' => 'Customer public reply']);
    TicketReply::factory()->for($this->ticket)->internalNote()->create(['user_id' => $this->agent->id, 'body' => 'Secret staff note']);
    TicketActivity::factory()->for($this->ticket)->create(['description' => 'Escalated to tier two']);

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

    $this->actingAs($this->agent)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSee($this->ticket->ticket_number)
        ->assertSee($foreign->ticket_number);
});

it('sends guests to the login page', function () {
    $this->get(route('tickets.show', $this->ticket))->assertRedirect(route('login'));
    $this->get(route('tickets.index'))->assertRedirect(route('login'));
});

it('lands staff on the staff dashboard and customers on their tickets', function () {
    $this->actingAs($this->agent)->get(route('dashboard'))->assertRedirect(route('staff.dashboard'));
    $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('tickets.index'));
});
