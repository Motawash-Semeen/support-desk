<?php

use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CloseTicket;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\ReopenTicket;
use App\Actions\Tickets\ResolveTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketCreated;
use App\Events\TicketResolved;
use App\Events\TicketStatusChanged;
use App\Events\TicketUpdated;
use App\Exceptions\InvalidTicketTransition;
use App\Jobs\AutoAssignTicket;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Sla\SlaCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->customer = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
    $this->category = Category::factory()->create();
    $this->data = [
        'subject' => 'Cannot log in',
        'description' => 'The login page says my password is wrong.',
        'priority' => 'high',
        'category_id' => $this->category->id,
    ];
});

/* CreateTicket ------------------------------------------------------- */

it('creates an open ticket with a number, SLA snapshot and activity', function () {
    // Thursday 2026-11-05 17:00 Dhaka (11:00 UTC), business hours Sun-Thu 09:00-18:00.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));
    config(['support.assignment.auto_on_create' => false]); // covered in AutoAssignmentTest
    $policy = SlaPolicy::factory()->forPriority(TicketPriority::High)->create();

    $ticket = app(CreateTicket::class)($this->customer, $this->data);

    // High = 480 business minutes: 60 on Thursday + 420 on Sunday = Sunday 16:00 Dhaka.
    // Warning = 384: 60 + 324 = Sunday 14:24 Dhaka.
    expect($ticket->ticket_number)->toBe('TKT-2026-000001')
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->created_by)->toBe($this->customer->id)
        ->and($ticket->sla_policy_id)->toBe($policy->id)
        ->and($ticket->sla_minutes)->toBe(480)
        ->and($ticket->due_at->toDateTimeString())->toBe('2026-11-08 10:00:00')
        ->and($ticket->sla_warning_at->toDateTimeString())->toBe('2026-11-08 08:24:00')
        ->and($ticket->activities()->sole()->type)->toBe(ActivityType::TicketCreated);
});

it('falls back to config SLA minutes when no policy exists', function () {
    $ticket = app(CreateTicket::class)($this->customer, [...$this->data, 'priority' => 'critical']);

    expect($ticket->sla_policy_id)->toBeNull()
        ->and($ticket->sla_minutes)->toBe(120);
});

it('dispatches TicketCreated only after the transaction commits', function () {
    $fired = 0;
    Event::listen(TicketCreated::class, function () use (&$fired) {
        $fired++;
    });

    try {
        DB::transaction(function () use (&$fired) {
            app(CreateTicket::class)($this->customer, $this->data);
            expect($fired)->toBe(0); // still inside the transaction

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($fired)->toBe(0)->and(Ticket::count())->toBe(0);

    app(CreateTicket::class)($this->customer, $this->data);

    expect($fired)->toBe(1);
});

it('queues auto-assignment after commit when enabled', function () {
    Queue::fake();

    $ticket = app(CreateTicket::class)($this->customer, $this->data);

    Queue::assertPushed(AutoAssignTicket::class, fn (AutoAssignTicket $job) => $job->ticketId === $ticket->id);
});

it('does not queue auto-assignment when disabled', function () {
    Queue::fake();
    config(['support.assignment.auto_on_create' => false]);

    app(CreateTicket::class)($this->customer, $this->data);

    Queue::assertNotPushed(AutoAssignTicket::class);
});

/* UpdateTicket ------------------------------------------------------- */

it('records one activity per changed field with old and new values', function () {
    Event::fake([TicketUpdated::class]);
    $ticket = Ticket::factory()->for($this->customer, 'creator')->priority(TicketPriority::Low)->create(['subject' => 'Old subject']);
    $other = Category::factory()->create(['name' => 'Billing']);

    app(UpdateTicket::class)($this->admin, $ticket, [
        'subject' => 'New subject',
        'priority' => 'critical',
        'category_id' => (string) $other->id,
        'description' => $ticket->description, // unchanged
    ]);

    $activities = $ticket->activities()->get()->keyBy(fn ($a) => $a->type->value);

    // subject, priority, category, plus the SLA recalculation the priority change triggers
    expect($activities)->toHaveCount(4)
        ->and($activities)->toHaveKey('sla_recalculated')
        ->and($activities['ticket_updated']->old_values)->toBe(['subject' => 'Old subject'])
        ->and($activities['ticket_updated']->new_values)->toBe(['subject' => 'New subject'])
        ->and($activities['priority_changed']->description)->toBe('Priority changed from Low to Critical')
        ->and($activities['category_changed']->description)->toContain('to Billing')
        ->and($activities['category_changed']->actor_id)->toBe($this->admin->id);

    Event::assertDispatched(TicketUpdated::class, fn ($e) => array_keys($e->changes) === ['subject', 'priority', 'category_id']);
});

it('recalculates the SLA from created_at when the priority changes', function () {
    $ticket = Ticket::factory()->priority(TicketPriority::Low)->create(['created_at' => now(), 'warning_notified_at' => now()]);

    $updated = app(UpdateTicket::class)($this->admin, $ticket, ['priority' => 'critical']);
    $expected = app(SlaCalculator::class)->calculate(TicketPriority::Critical, $ticket->created_at);

    expect($updated->sla_minutes)->toBe(120)
        ->and($updated->due_at->equalTo($expected->dueAt))->toBeTrue()
        ->and($updated->warning_notified_at)->toBeNull(); // new deadline is in the future
});

it('records nothing when no field changed', function () {
    $ticket = Ticket::factory()->create();

    app(UpdateTicket::class)($this->admin, $ticket, ['subject' => $ticket->subject, 'category_id' => (string) $ticket->category_id]);

    expect($ticket->activities()->count())->toBe(0);
});

/* ChangeTicketStatus -------------------------------------------------- */

it('throws InvalidTicketTransition for an illegal move', function () {
    $ticket = Ticket::factory()->inProgress()->create();

    expect(fn () => app(ChangeTicketStatus::class)($this->admin, $ticket, TicketStatus::Closed))
        ->toThrow(InvalidTicketTransition::class, 'cannot move from In progress to Closed');

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->activities()->count())->toBe(0);
});

it('resolves: sets resolved_at, records activity and dispatches TicketResolved', function () {
    Event::fake([TicketResolved::class, TicketStatusChanged::class]);
    $ticket = Ticket::factory()->inProgress()->create();

    $resolved = app(ResolveTicket::class)($this->agent, $ticket, 'Password reset sent');

    expect($resolved->status)->toBe(TicketStatus::Resolved)
        ->and($resolved->resolved_at)->not->toBeNull()
        ->and($resolved->activities()->sole()->new_values)->toBe(['status' => 'resolved', 'reason' => 'Password reset sent']);

    Event::assertDispatched(TicketResolved::class);
    Event::assertDispatched(TicketStatusChanged::class, fn ($e) => $e->from === TicketStatus::InProgress && $e->to === TicketStatus::Resolved);
});

it('closes a resolved ticket and sets closed_at', function () {
    $ticket = Ticket::factory()->resolved()->create();

    $closed = app(CloseTicket::class)($this->customer, $ticket);

    expect($closed->status)->toBe(TicketStatus::Closed)->and($closed->closed_at)->not->toBeNull();
});

it('reopens a resolved ticket: clears finished timestamps and restarts the SLA', function () {
    // Reopened Thursday 2026-11-05 17:00 Dhaka (11:00 UTC).
    $this->travelTo(CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC'));
    $ticket = Ticket::factory()->resolved()->escalated()->priority(TicketPriority::High)->create();

    $reopened = app(ReopenTicket::class)($this->customer, $ticket, 'Still broken');

    // Fresh 480 business minutes from the reopen time: Sunday 16:00 Dhaka = 10:00 UTC.
    expect($reopened->status)->toBe(TicketStatus::InProgress)
        ->and($reopened->resolved_at)->toBeNull()
        ->and($reopened->closed_at)->toBeNull()
        ->and($reopened->is_escalated)->toBeFalse()
        ->and($reopened->due_at->toDateTimeString())->toBe('2026-11-08 10:00:00')
        ->and($reopened->activities()->pluck('type')->all())->toEqualCanonicalizing([ActivityType::Reopened, ActivityType::SlaRecalculated]);
});

it('lets only an admin reopen a closed ticket', function () {
    $ticket = Ticket::factory()->closed()->create();

    expect(fn () => app(ReopenTicket::class)($this->agent, $ticket))->toThrow(InvalidTicketTransition::class);

    expect(app(ReopenTicket::class)($this->admin, $ticket)->status)->toBe(TicketStatus::InProgress);
});
