<?php

use App\Actions\Sla\EscalateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\ActivityType;
use App\Enums\EscalationKind;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\SlaBreached;
use App\Events\SlaWarningReached;
use App\Jobs\AutoAssignTicket;
use App\Jobs\EscalateTicketJob;
use App\Models\Notification as DatabaseNotificationModel;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEscalatedNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    Carbon::setTestNow(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC')); // Sunday 12:00 Dhaka

    $this->agent = User::factory()->agent()->create();
    $this->admins = User::factory()->admin()->count(2)->create();
});

afterEach(fn () => Carbon::setTestNow());

/* Warning ---------------------------------------------------------------- */

it('queues one warning job when sla_warning_at is crossed, even if the scan runs three times', function () {
    Queue::fake();
    $ticket = Ticket::factory()->inProgress()->assigned($this->agent)->create([
        'sla_warning_at' => now()->addSeconds(30),
        'due_at' => now()->addHour(),
    ]);

    $this->artisan('sla:scan');
    Queue::assertNothingPushed(); // not crossed yet

    Carbon::setTestNow(now()->addMinute()); // crossed
    $this->artisan('sla:scan');
    $this->artisan('sla:scan');
    $this->artisan('sla:scan');

    // ShouldBeUnique (ticket + kind) collapses the three dispatches into one job.
    Queue::assertPushed(EscalateTicketJob::class, 1);
    Queue::assertPushed(EscalateTicketJob::class, fn ($job) => $job->ticketId === $ticket->id && $job->kind === EscalationKind::Warning);
});

it('applies a warning exactly once however many times the job runs', function () {
    Event::fake([SlaWarningReached::class]);
    $ticket = Ticket::factory()->approaching()->assigned($this->agent)->create();

    $job = new EscalateTicketJob($ticket->id, EscalationKind::Warning);
    $job->handle(app(EscalateTicket::class));
    $job->handle(app(EscalateTicket::class)); // duplicate / retry
    $job->handle(app(EscalateTicket::class));

    Event::assertDispatchedTimes(SlaWarningReached::class, 1);
    expect($ticket->activities()->where('type', ActivityType::SlaWarning)->count())->toBe(1)
        ->and($ticket->fresh()->warning_notified_at)->not->toBeNull();
});

it('warns exactly once across three scans with a real (sync) queue', function () {
    Event::fake([SlaWarningReached::class]);
    $ticket = Ticket::factory()->approaching()->assigned($this->agent)->create();

    foreach (range(1, 3) as $run) {
        $this->artisan('sla:scan')->assertSuccessful();
    }

    Event::assertDispatchedTimes(SlaWarningReached::class, 1);
    expect($ticket->activities()->where('type', ActivityType::SlaWarning)->count())->toBe(1);
});

/* Breach ----------------------------------------------------------------- */

it('marks a breach escalated with both activities and one SlaBreached event', function () {
    Event::fake([SlaBreached::class]);
    $ticket = Ticket::factory()->inProgress()->assigned($this->agent)->create(['due_at' => now()->subMinutes(5), 'sla_warning_at' => now()->subHour()]);

    $this->artisan('sla:scan');
    $this->artisan('sla:scan');

    $fresh = $ticket->fresh();

    expect($fresh->is_escalated)->toBeTrue()
        ->and($fresh->escalation_level)->toBe(1)
        ->and($fresh->breached_notified_at)->not->toBeNull()
        ->and($ticket->activities()->whereIn('type', [ActivityType::SlaBreached, ActivityType::Escalated])->orderBy('id')->pluck('type')->all())
        ->toBe([ActivityType::SlaBreached, ActivityType::Escalated]);

    Event::assertDispatchedTimes(SlaBreached::class, 1);
});

it('tries to auto-assign a ticket that is unassigned at breach time', function () {
    Queue::fake();
    $ticket = Ticket::factory()->create(['due_at' => now()->subMinute(), 'sla_warning_at' => now()->subHour()]);

    expect(app(EscalateTicket::class)($ticket->id, EscalationKind::Breach))->toBeTrue();

    Queue::assertPushed(AutoAssignTicket::class, fn ($job) => $job->ticketId === $ticket->id);
});

it('never escalates resolved or closed tickets', function (string $state) {
    Queue::fake();
    $ticket = Ticket::factory()->{$state}()->create(['due_at' => now()->subDay(), 'sla_warning_at' => now()->subDays(2)]);

    $this->artisan('sla:scan');
    Queue::assertNothingPushed();

    // Even a job queued just before the ticket was resolved does nothing.
    expect(app(EscalateTicket::class)($ticket->id, EscalationKind::Warning))->toBeFalse()
        ->and(app(EscalateTicket::class)($ticket->id, EscalationKind::Breach))->toBeFalse()
        ->and($ticket->fresh()->is_escalated)->toBeFalse()
        ->and($ticket->activities()->count())->toBe(0);
})->with(['resolved', 'closed']);

it('re-arms the flags when a priority change moves the deadline, keeping the escalation history', function () {
    $ticket = Ticket::factory()->inProgress()->assigned($this->agent)->priority(TicketPriority::Critical)->create([
        'created_at' => now()->subHours(3),
        'due_at' => now()->subMinute(),
        'sla_warning_at' => now()->subMinutes(30),
    ]);

    $this->artisan('sla:scan'); // warning + breach + escalation level 1
    expect($ticket->fresh()->breached_notified_at)->not->toBeNull();

    app(UpdateTicket::class)($this->admins[0], $ticket, ['priority' => 'low']); // 2880 business minutes from creation

    $rearmed = $ticket->fresh();
    expect($rearmed->due_at->isFuture())->toBeTrue()
        ->and($rearmed->breached_notified_at)->toBeNull()
        ->and($rearmed->warning_notified_at)->toBeNull()
        ->and($ticket->activities()->where('type', ActivityType::Escalated)->count())->toBe(1); // history kept

    $this->artisan('sla:scan');
    expect($ticket->activities()->where('type', ActivityType::SlaBreached)->count())->toBe(1); // not due yet

    Carbon::setTestNow($rearmed->due_at->addMinute());
    $this->artisan('sla:scan');

    expect($ticket->activities()->where('type', ActivityType::SlaBreached)->count())->toBe(2)
        ->and($ticket->activities()->where('type', ActivityType::Escalated)->count())->toBe(2);
});

/* Level 2 ---------------------------------------------------------------- */

it('escalates to level 2 once, after the configured minutes past the deadline', function () {
    config(['support.escalation.level2_after_minutes' => 120]);
    $ticket = Ticket::factory()->inProgress()->assigned($this->agent)->create(['due_at' => now()->subMinutes(30), 'sla_warning_at' => now()->subHours(2)]);

    $this->artisan('sla:scan');
    expect($ticket->fresh()->escalation_level)->toBe(1);

    Carbon::setTestNow(now()->addMinutes(80)); // 110 minutes past due
    $this->artisan('sla:scan');
    expect($ticket->fresh()->escalation_level)->toBe(1);

    Carbon::setTestNow(now()->addMinutes(15)); // 125 minutes past due
    $this->artisan('sla:scan');
    $this->artisan('sla:scan');

    expect($ticket->fresh()->escalation_level)->toBe(2)
        ->and($ticket->activities()->where('type', ActivityType::Escalated)->count())->toBe(2);

    foreach ($this->admins as $admin) {
        Notification::assertSentToTimes($admin, TicketEscalatedNotification::class, 1);
    }
    Notification::assertNotSentTo($this->agent, TicketEscalatedNotification::class);
});

/* Scan mechanics ----------------------------------------------------------- */

it('dispatches every candidate in id order, in --chunk sized batches', function () {
    Queue::fake();
    Ticket::factory()->count(5)->inProgress()->create(['due_at' => now()->subMinute(), 'sla_warning_at' => now()->subHour(), 'warning_notified_at' => now()]);

    $this->artisan('sla:scan', ['--chunk' => 2, '--verbose' => true])
        ->expectsOutputToContain('queued 0 warning, 5 breach')
        ->assertSuccessful();

    Queue::assertPushed(EscalateTicketJob::class, 5);
});

it('stays quiet unless --verbose', function () {
    $this->artisan('sla:scan')->doesntExpectOutputToContain('SLA scan queued')->assertSuccessful();
});

it('is a unique, retrying job per ticket and kind', function () {
    $job = new EscalateTicketJob(7, EscalationKind::Breach);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('7:breach')
        ->and($job->tries)->toBe(3);
});

/* Auto-close and pruning --------------------------------------------------- */

it('closes tickets resolved for N days as the system', function () {
    $old = Ticket::factory()->resolved()->create(['resolved_at' => now()->subDays(4)]);
    $recent = Ticket::factory()->resolved()->create(['resolved_at' => now()->subDay()]);

    $this->artisan('tickets:auto-close-resolved')->expectsOutputToContain('Closed 1 ticket(s)')->assertSuccessful();

    $activity = $old->activities()->where('type', ActivityType::StatusChanged)->sole();

    expect($old->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($recent->fresh()->status)->toBe(TicketStatus::Resolved)
        ->and($activity->actor_id)->toBeNull()
        ->and($activity->new_values['reason'])->toBe('Closed automatically after 3 days resolved');

    $this->artisan('tickets:auto-close-resolved', ['--days' => 1])->expectsOutputToContain('Closed 1 ticket(s)');
    expect($recent->fresh()->status)->toBe(TicketStatus::Closed);
});

it('prunes read notifications older than 90 days and keeps the rest', function () {
    $user = User::factory()->create();
    $make = fn (?CarbonInterface $readAt, CarbonInterface $createdAt) => DatabaseNotificationModel::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['message' => 'x'],
        'read_at' => $readAt,
        'created_at' => $createdAt,
    ]);

    $oldRead = $make(now()->subDays(100), now()->subDays(120));
    $recentRead = $make(now()->subDays(10), now()->subDays(20));
    $oldUnread = $make(null, now()->subDays(200));

    $this->artisan('model:prune', ['--model' => [DatabaseNotificationModel::class]])->assertSuccessful();

    expect(DatabaseNotificationModel::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$recentRead->id, $oldUnread->id])->sort()->values()->all())
        ->and($user->notifications()->count())->toBe(2);
});

/* Schedule ------------------------------------------------------------------ */

it('registers every task with the right cadence and guards', function (string $command, string $expression, bool $guarded, bool $background) {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', $command));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe($expression)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBe($background);

    if ($guarded) {
        expect($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(10);
    }
})->with([
    ['sla:scan', '* * * * *', true, true],
    ['tickets:assign-unassigned', '*/5 * * * *', true, true],
    ['tickets:auto-close-resolved', '0 2 * * *', true, false],
    ['attachments:purge-orphans', '30 3 * * *', true, false],
    ['model:prune', '0 4 * * *', true, false],
    ['queue:prune-failed', '0 0 * * 0', false, false],
]);
