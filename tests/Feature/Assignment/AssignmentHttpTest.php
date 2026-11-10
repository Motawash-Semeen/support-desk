<?php

use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->agentA = User::factory()->agent()->create();
    $this->agentB = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
    $this->customer = User::factory()->create();
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->create();
});

/* Accept --------------------------------------------------------------- */

it('answers the second accept with 409 Conflict and a clear message', function () {
    $this->actingAs($this->agentA)
        ->post(route('tickets.accept', $this->ticket))
        ->assertRedirect(route('tickets.show', $this->ticket))
        ->assertSessionHas('status');

    $this->actingAs($this->agentB)
        ->post(route('tickets.accept', $this->ticket))
        ->assertStatus(409)
        ->assertSee('This ticket was just taken by another agent.')
        ->assertSee(route('staff.unassigned'));

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentA->id);
});

it('answers 409 JSON for API-style requests', function () {
    $this->actingAs($this->agentA)->post(route('tickets.accept', $this->ticket))->assertRedirect();

    $this->actingAs($this->agentB)
        ->postJson(route('tickets.accept', $this->ticket))
        ->assertStatus(409)
        ->assertJsonPath('message', 'This ticket was just taken by another agent.');
});

it('treats a double click by the winner as success', function () {
    $this->actingAs($this->agentA)->post(route('tickets.accept', $this->ticket));

    $this->actingAs($this->agentA)
        ->post(route('tickets.accept', $this->ticket))
        ->assertRedirect(route('tickets.show', $this->ticket));
});

it('does not let customers or admins self-accept', function () {
    $this->actingAs($this->customer)->post(route('tickets.accept', $this->ticket))->assertForbidden();
    $this->actingAs($this->admin)->post(route('tickets.accept', $this->ticket))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('tickets.accept', $this->ticket))->assertNotFound();
});

it('refuses to accept a finished ticket', function () {
    $closed = Ticket::factory()->closed()->create(['assigned_agent_id' => null]);

    $this->actingAs($this->agentA)->post(route('tickets.accept', $closed))->assertForbidden();
});

/* Queue page ------------------------------------------------------------ */

it('lists unassigned active tickets oldest due first, for staff only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC'));
    $later = Ticket::factory()->create(['due_at' => now()->addDays(2)]);
    $sooner = Ticket::factory()->create(['due_at' => now()->addHour()]);
    $assigned = Ticket::factory()->assigned()->create();
    $closed = Ticket::factory()->closed()->create(['assigned_agent_id' => null]);
    $this->ticket->update(['due_at' => now()->addDay()]);

    $this->actingAs($this->agentA)->get(route('staff.unassigned'))
        ->assertOk()
        ->assertSeeInOrder([$sooner->ticket_number, $this->ticket->ticket_number, $later->ticket_number])
        ->assertDontSee($assigned->ticket_number)
        ->assertDontSee($closed->ticket_number)
        ->assertSee(route('tickets.accept', $sooner));

    $this->actingAs($this->admin)->get(route('staff.unassigned'))->assertOk()->assertDontSee(route('tickets.accept', $sooner));
    $this->actingAs($this->customer)->get(route('staff.unassigned'))->assertForbidden();
});

/* Admin assignment ------------------------------------------------------ */

it('lets an admin assign and reassign from the ticket page', function () {
    $this->actingAs($this->admin)->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertSee('name="expected_agent_id" value=""', false);

    $this->actingAs($this->admin)
        ->post(route('tickets.assign', $this->ticket), ['agent_id' => $this->agentA->id, 'expected_agent_id' => ''])
        ->assertRedirect(route('tickets.show', $this->ticket));

    $this->actingAs($this->admin)
        ->post(route('tickets.assign', $this->ticket), ['agent_id' => $this->agentB->id, 'expected_agent_id' => $this->agentA->id])
        ->assertRedirect(route('tickets.show', $this->ticket));

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentB->id);
});

it('gives the second of two concurrent admins a 409 instead of overwriting', function () {
    $otherAdmin = User::factory()->admin()->create();
    $agentC = User::factory()->agent()->create();

    // Both admins loaded the page while the ticket was unassigned.
    $this->actingAs($this->admin)
        ->post(route('tickets.assign', $this->ticket), ['agent_id' => $this->agentA->id, 'expected_agent_id' => ''])
        ->assertRedirect();

    $this->actingAs($otherAdmin)
        ->post(route('tickets.assign', $this->ticket), ['agent_id' => $agentC->id, 'expected_agent_id' => ''])
        ->assertStatus(409)
        ->assertSee('The assignment of this ticket changed while you were looking at it.');

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentA->id);
});

it('only lets admins assign, and only to available agents', function () {
    $unavailable = User::factory()->unavailable()->create();

    $this->actingAs($this->agentA)
        ->post(route('tickets.assign', $this->ticket), ['agent_id' => $this->agentA->id])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post(route('tickets.assign', $this->ticket), ['agent_id' => $unavailable->id])
        ->assertSessionHasErrors('agent_id');

    $this->actingAs($this->admin)
        ->post(route('tickets.assign', $this->ticket), ['agent_id' => $this->customer->id])
        ->assertSessionHasErrors('agent_id');
});

/* Release ---------------------------------------------------------------- */

it('lets the assignee release the ticket to the queue', function () {
    $this->actingAs($this->agentA)->post(route('tickets.accept', $this->ticket));

    $this->actingAs($this->agentA)
        ->post(route('tickets.release', $this->ticket))
        ->assertRedirect(route('tickets.show', $this->ticket));

    expect($this->ticket->fresh()->assigned_agent_id)->toBeNull()
        ->and($this->ticket->fresh()->status)->toBe(TicketStatus::Open);
});

it('does not let another agent release someone else\'s ticket', function () {
    $this->actingAs($this->agentA)->post(route('tickets.accept', $this->ticket));

    // Once agent A owns it, agent B can no longer even see the ticket.
    $this->actingAs($this->agentB)->post(route('tickets.release', $this->ticket))->assertNotFound();
});

/* Availability ------------------------------------------------------------- */

it('lets agents toggle their availability from the profile', function () {
    $this->actingAs($this->agentA)->get(route('profile.edit'))->assertOk()->assertSee('Go unavailable');

    $this->actingAs($this->agentA)
        ->patch(route('profile.availability.update'), ['is_available' => '0'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($this->agentA->fresh()->is_available)->toBeFalse();
});

it('has no availability setting for customers and admins', function (string $who) {
    $this->actingAs($this->{$who})->patch(route('profile.availability.update'), ['is_available' => '0'])->assertForbidden();
    $this->actingAs($this->{$who})->get(route('profile.edit'))->assertOk()->assertDontSee('Go unavailable');
})->with(['customer', 'admin']);
