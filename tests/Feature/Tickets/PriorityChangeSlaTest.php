<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->agent = User::factory()->agent()->create();
});

it('moves due_at from the ORIGINAL created_at and logs sla_recalculated', function () {
    // Created Thursday 2026-11-05 17:00 Dhaka (11:00 UTC) as low priority.
    $createdAt = CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC');
    $this->travelTo(CarbonImmutable::parse('2026-11-08 03:30:00', 'UTC')); // Sunday 09:30 Dhaka

    $ticket = Ticket::factory()->assigned($this->agent)->priority(TicketPriority::Low)->create([
        'created_at' => $createdAt,
        'due_at' => CarbonImmutable::parse('2026-11-15 05:00:00', 'UTC'),
    ]);

    $this->actingAs($this->agent)
        ->patch(route('tickets.update', $ticket), ['priority' => 'critical'])
        ->assertRedirect(route('tickets.show', $ticket));

    $ticket->refresh();

    // Critical = 120 business minutes from Thursday 17:00: 60 Thursday + 60 Sunday = Sunday 10:00 Dhaka.
    expect($ticket->priority)->toBe(TicketPriority::Critical)
        ->and($ticket->sla_minutes)->toBe(120)
        ->and($ticket->due_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-08 10:00')
        ->and($ticket->sla_warning_at->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'))->toBe('2026-11-08 09:36');

    $activity = $ticket->activities()->where('type', ActivityType::SlaRecalculated)->sole();

    expect($activity->actor_id)->toBe($this->agent->id)
        ->and($activity->old_values['due_at'])->toBe('2026-11-15T05:00:00+00:00')
        ->and($activity->new_values['due_at'])->toBe('2026-11-08T04:00:00+00:00')
        ->and($activity->new_values['sla_minutes'])->toBe(120)
        ->and($activity->description)->toContain('priority changed');
});

it('clears notification markers when the new deadline is in the future', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 03:30:00', 'UTC'));
    $ticket = Ticket::factory()->assigned($this->agent)->priority(TicketPriority::High)->create([
        'created_at' => now()->subMinutes(10),
        'warning_notified_at' => now(),
        'breached_notified_at' => now(),
    ]);

    $this->actingAs($this->agent)->patch(route('tickets.update', $ticket), ['priority' => 'low']);

    expect($ticket->fresh()->due_at->isFuture())->toBeTrue()
        ->and($ticket->fresh()->warning_notified_at)->toBeNull()
        ->and($ticket->fresh()->breached_notified_at)->toBeNull();
});

it('keeps notification markers when the recalculated deadline has already passed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 03:30:00', 'UTC'));
    $ticket = Ticket::factory()->assigned($this->agent)->priority(TicketPriority::Low)->create([
        'created_at' => now()->subDays(20),
        'breached_notified_at' => now()->subDay(),
    ]);

    $this->actingAs($this->agent)->patch(route('tickets.update', $ticket), ['priority' => 'critical']);

    expect($ticket->fresh()->due_at->isPast())->toBeTrue()
        ->and($ticket->fresh()->breached_notified_at)->not->toBeNull();
});

it('records nothing SLA-related when the priority does not change', function () {
    $ticket = Ticket::factory()->assigned($this->agent)->priority(TicketPriority::High)->create();

    $this->actingAs($this->agent)->patch(route('tickets.update', $ticket), ['priority' => 'high', 'subject' => 'Renamed']);

    expect($ticket->activities()->where('type', ActivityType::SlaRecalculated)->exists())->toBeFalse();
});
