<?php

use App\Actions\Sla\EscalateTicket;
use App\Enums\ActivityType;
use App\Enums\EscalationKind;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaApproachingNotification;
use App\Notifications\SlaBreachedNotification;
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

    Notification::assertSentToTimes($this->agent, SlaApproachingNotification::class, 1);
    Notification::assertNotSentTo($this->admins, SlaApproachingNotification::class);

    expect($ticket->fresh()->warning_notified_at)->not->toBeNull()
        ->and($ticket->activities()->where('type', ActivityType::SlaWarning)->count())->toBe(1);
});

it('warns admins about an unassigned ticket', function () {
    Ticket::factory()->approaching()->create();

    $this->artisan('sla:scan')->assertSuccessful();

    Notification::assertSentTo($this->admins, SlaApproachingNotification::class);
});

it('alerts the assignee and every admin once on a breach', function () {
    $ticket = Ticket::factory()->inProgress()->breached()->assigned($this->agent)->create();

    $this->artisan('sla:scan --verbose')->expectsOutputToContain('queued 0 warning, 1 breach')->assertSuccessful();
    $this->artisan('sla:scan')->assertSuccessful();

    Notification::assertSentToTimes($this->agent, SlaBreachedNotification::class, 1);
    foreach ($this->admins as $admin) {
        Notification::assertSentToTimes($admin, SlaBreachedNotification::class, 1);
    }

    expect($ticket->fresh()->breached_notified_at)->not->toBeNull();
});

it('lets only one of two overlapping runs claim a milestone', function () {
    $ticket = Ticket::factory()->inProgress()->breached()->assigned($this->agent)->create();

    expect(app(EscalateTicket::class)($ticket->id, EscalationKind::Breach))->toBeTrue()
        ->and(app(EscalateTicket::class)($ticket->id, EscalationKind::Breach))->toBeFalse();

    Notification::assertSentToTimes($this->agent, SlaBreachedNotification::class, 1);
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

    Notification::assertSentToTimes($this->agent, SlaApproachingNotification::class, 2);
});
