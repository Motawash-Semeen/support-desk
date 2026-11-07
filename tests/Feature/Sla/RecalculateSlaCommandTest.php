<?php

use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 03:00:00', 'UTC')); // Sunday 09:00 Dhaka
    $this->policy = SlaPolicy::factory()->forPriority(TicketPriority::Critical)->create(['resolution_minutes' => 120]);

    // Snapshots taken under the old rule (120 minutes from Sunday 09:00 = 11:00 Dhaka).
    $snapshot = ['created_at' => now(), 'sla_minutes' => 120, 'due_at' => now()->addHours(2), 'sla_warning_at' => now()->addMinutes(96)];
    $this->active = Ticket::factory()->priority(TicketPriority::Critical)->create($snapshot);
    $this->resolved = Ticket::factory()->priority(TicketPriority::Critical)->resolved()->create($snapshot);

    $this->policy->update(['resolution_minutes' => 60]); // the rule changes afterwards
});

it('requires --open as an explicit opt-in', function () {
    $this->artisan('sla:recalculate')->assertFailed();

    expect($this->active->fresh()->sla_minutes)->toBe(120);
});

it('reports without saving on --dry-run', function () {
    $this->artisan('sla:recalculate', ['--open' => true, '--dry-run' => true])
        ->expectsOutputToContain('Would update 1 of 1 active tickets')
        ->assertSuccessful();

    expect($this->active->fresh()->sla_minutes)->toBe(120);
});

it('re-applies the current rules to active tickets only and logs it as the system', function () {
    $this->artisan('sla:recalculate', ['--open' => true])
        ->expectsOutputToContain('Updated 1 of 1 active tickets')
        ->assertSuccessful();

    $active = $this->active->fresh();

    expect($active->sla_minutes)->toBe(60)
        ->and($active->due_at->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('10:00')
        ->and($this->resolved->fresh()->sla_minutes)->toBe(120); // finished tickets keep their snapshot

    $activity = $active->activities()->where('type', ActivityType::SlaRecalculated)->sole();
    expect($activity->actor_id)->toBeNull();
});

it('is idempotent', function () {
    $this->artisan('sla:recalculate', ['--open' => true])->assertSuccessful();
    $this->artisan('sla:recalculate', ['--open' => true])
        ->expectsOutputToContain('Updated 0 of 1 active tickets')
        ->assertSuccessful();

    expect($this->active->activities()->where('type', ActivityType::SlaRecalculated)->count())->toBe(1);
});
