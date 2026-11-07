<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->customer = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->assigned($this->agent)->create();
});

it('shows customers only customer-visible activity types', function () {
    foreach (ActivityType::cases() as $type) {
        TicketActivity::factory()->for($this->ticket)->create(['type' => $type, 'description' => "Entry for {$type->value}"]);
    }

    $customerPage = $this->actingAs($this->customer)->get(route('tickets.show', $this->ticket))->assertOk();
    $agentPage = $this->actingAs($this->agent)->get(route('tickets.show', $this->ticket))->assertOk();

    foreach (ActivityType::cases() as $type) {
        $text = "Entry for {$type->value}";

        $type->isCustomerVisible() ? $customerPage->assertSee($text) : $customerPage->assertDontSee($text);
        $agentPage->assertSee($text);
    }
});

it('treats notes, assignment, SLA internals and escalation as internal', function () {
    $internal = collect(ActivityType::cases())->reject->isCustomerVisible()->pluck('value')->sort()->values()->all();

    expect($internal)->toBe(collect([
        'assigned', 'auto_assign_skipped', 'escalated', 'internal_note_added', 'reassigned',
        'released', 'sla_breached', 'sla_recalculated', 'sla_warning',
    ])->sort()->values()->all());
});

it('paginates the timeline by 20 and toggles the order', function () {
    foreach (range(1, 25) as $i) {
        $this->travel(1)->minutes();
        TicketActivity::factory()->for($this->ticket)->create(['description' => sprintf('Step %02d', $i)]);
    }

    $this->actingAs($this->agent)->get(route('tickets.show', $this->ticket))
        ->assertSeeInOrder(['Step 25', 'Step 24'])
        ->assertDontSee('Step 05')
        ->assertSee('timeline_page=2', false);

    $this->actingAs($this->agent)->get(route('tickets.show', [$this->ticket, 'timeline_page' => 2]))
        ->assertSee('Step 05')
        ->assertDontSee('Step 25');

    $this->actingAs($this->agent)->get(route('tickets.show', [$this->ticket, 'timeline' => 'oldest']))
        ->assertSeeInOrder(['Step 01', 'Step 02'])
        ->assertSee('Newest first');
});

it('renders a missing actor as System', function () {
    TicketActivity::factory()->for($this->ticket)->create(['actor_id' => null, 'description' => 'Scheduler did it']);

    $this->actingAs($this->agent)->get(route('tickets.show', $this->ticket))->assertSeeInOrder(['Scheduler did it', 'System']);
});
