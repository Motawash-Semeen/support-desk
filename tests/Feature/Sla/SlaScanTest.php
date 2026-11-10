<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Notifications\TicketNotification;
use App\Modules\Sla\Enums\EscalationKind;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| Who hears about SLA warnings and breaches. The queue is sync in tests, so
| the EscalateTicketJob that sla:scan queues runs immediately.
*/

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC'));

    $this->agent = User::factory()->agent()->create();
    $this->admins = User::factory()->admin()->count(2)->create();
});

it('warns the assignee once when a ticket reaches its warning point', function () {
    $ticket = Ticket::factory()->approaching()->assigned($this->agent)->create();

    $this->artisan('sla:scan --verbose')->expectsOutputToContain('queued 1 warning, 0 breach')->assertSuccessful();
    $this->artisan('sla:scan --verbose')->expectsOutputToContain('queued 0 warning, 0 breach')->assertSuccessful();

    expect(Notification::sent($this->agent, TicketNotification::class, ofKind('sla_warning')))->toHaveCount(1);
    Notification::assertNotSentTo($this->admins, TicketNotification::class, ofKind('sla_warning'));

    expect($ticket->fresh()->warning_notified_at)->not->toBeNull()
        ->and($ticket->activities()->where('type', ActivityType::SlaWarning)->count())->toBe(1);
});

it('warns admins about an unassigned ticket', function () {
    Ticket::factory()->approaching()->create();

    $this->artisan('sla:scan')->assertSuccessful();

    Notification::assertSentTo($this->admins, TicketNotification::class, ofKind('sla_warning'));
});

it('alerts the assignee and every admin once on a breach', function () {
    $ticket = Ticket::factory()->inProgress()->breached()->assigned($this->agent)->create();

    $this->artisan('sla:scan --verbose')->expectsOutputToContain('queued 0 warning, 1 breach')->assertSuccessful();
    $this->artisan('sla:scan')->assertSuccessful();

    expect(Notification::sent($this->agent, TicketNotification::class, ofKind('sla_breached')))->toHaveCount(1);
    foreach ($this->admins as $admin) {
        expect(Notification::sent($admin, TicketNotification::class, ofKind('sla_breached')))->toHaveCount(1);
    }

    expect($ticket->fresh()->breached_notified_at)->not->toBeNull();
});

it('lets only one of two overlapping runs claim a milestone', function () {
    $ticket = Ticket::factory()->inProgress()->breached()->assigned($this->agent)->create();

    expect(app(SlaService::class)->escalate($ticket->id, EscalationKind::Breach))->toBeTrue()
        ->and(app(SlaService::class)->escalate($ticket->id, EscalationKind::Breach))->toBeFalse();

    expect(Notification::sent($this->agent, TicketNotification::class, ofKind('sla_breached')))->toHaveCount(1);
});

it('ignores finished and on-track tickets', function () {
    Ticket::factory()->resolved()->create(['due_at' => now()->subDay()]);
    Ticket::factory()->create(['due_at' => now()->addDay(), 'sla_warning_at' => now()->addHours(12)]);

    $this->artisan('sla:scan --verbose')->expectsOutputToContain('queued 0 warning, 0 breach and 0 level-2')->assertSuccessful();

    Notification::assertNothingSent();
});

it('notifies again after the deadline moves and the markers are cleared', function () {
    $ticket = Ticket::factory()->approaching()->assigned($this->agent)->create();
    $this->artisan('sla:scan');

    // e.g. a reopen or SLA recalculation clears the marker for the new deadline
    $ticket->fresh()->update(['warning_notified_at' => null]);
    $this->artisan('sla:scan');

    expect(Notification::sent($this->agent, TicketNotification::class, ofKind('sla_warning')))->toHaveCount(2);
});
