<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Services\TicketActivityRecorder;
use App\Modules\Assignment\Actions\ClaimTicket;
use App\Modules\Assignment\Jobs\AutoAssignTicket;
use App\Modules\Assignment\Services\AssignmentStrategy;
use App\Modules\Assignment\Services\LeastWorkloadStrategy;
use App\Modules\Assignment\Services\RoundRobinStrategy;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** Run the job synchronously, exactly as a worker would. */
function autoAssign(Ticket $ticket): void
{
    (new AutoAssignTicket($ticket->id))->handle(app(AssignmentStrategy::class), app(ClaimTicket::class), app(TicketActivityRecorder::class));
}

beforeEach(function () {
    $this->category = Category::factory()->create(['name' => 'Billing']);
});

it('spreads 6 tickets 2-2-2 over 3 agents and gives none to an unavailable agent', function () {
    $agents = User::factory()->agent()->count(3)->create();
    $away = User::factory()->unavailable()->create();

    $tickets = Ticket::factory()->count(6)->for($this->category)->priority(TicketPriority::Medium)->create();
    $tickets->each(fn (Ticket $ticket) => autoAssign($ticket));

    $counts = Ticket::query()->whereNotNull('assigned_agent_id')->get()->countBy('assigned_agent_id');

    expect($counts->all())->toEqualCanonicalizing([$agents[0]->id => 2, $agents[1]->id => 2, $agents[2]->id => 2])
        ->and($counts->get($away->id))->toBeNull();
});

it('prefers a specialist in the ticket category over agents of other categories', function () {
    $technical = Category::factory()->create(['name' => 'Technical']);
    $billingSpecialist = User::factory()->agent()->create();
    $billingSpecialist->categories()->attach($this->category);
    $technicalSpecialist = User::factory()->agent()->create();
    $technicalSpecialist->categories()->attach($technical);

    // The billing specialist is busier, yet still the only candidate for a billing ticket.
    Ticket::factory()->count(3)->assigned($billingSpecialist)->create();

    $ticket = Ticket::factory()->for($this->category)->create();
    autoAssign($ticket);

    expect($ticket->fresh()->assigned_agent_id)->toBe($billingSpecialist->id);
});

it('counts generalists (no categories) as candidates for every category', function () {
    $specialist = User::factory()->agent()->create();
    $specialist->categories()->attach($this->category);
    $generalist = User::factory()->agent()->create();
    Ticket::factory()->count(2)->assigned($specialist)->create();

    $ticket = Ticket::factory()->for($this->category)->create();
    autoAssign($ticket);

    expect($ticket->fresh()->assigned_agent_id)->toBe($generalist->id);
});

it('falls back to all available agents when nobody covers the category', function () {
    $other = Category::factory()->create();
    $agent = User::factory()->agent()->create();
    $agent->categories()->attach($other);

    $ticket = Ticket::factory()->for($this->category)->create();
    autoAssign($ticket);

    expect($ticket->fresh()->assigned_agent_id)->toBe($agent->id);
});

it('weights workload by priority', function () {
    $carriesCritical = User::factory()->agent()->create();
    $carriesTwoLow = User::factory()->agent()->create();
    Ticket::factory()->inProgress()->assigned($carriesCritical)->priority(TicketPriority::Critical)->create(); // 3
    Ticket::factory()->count(2)->inProgress()->assigned($carriesTwoLow)->priority(TicketPriority::Low)->create(); // 2

    $ticket = Ticket::factory()->for($this->category)->create();
    autoAssign($ticket);

    expect($ticket->fresh()->assigned_agent_id)->toBe($carriesTwoLow->id);
});

it('ignores finished tickets in the workload and breaks ties by least recently assigned', function () {
    $busyInThePast = User::factory()->agent()->create(['last_assigned_at' => now()->subHour()]);
    $assignedLongAgo = User::factory()->agent()->create(['last_assigned_at' => now()->subDays(3)]);
    Ticket::factory()->count(5)->closed()->assigned($busyInThePast)->create();

    $ticket = Ticket::factory()->for($this->category)->create();
    autoAssign($ticket);

    expect($ticket->fresh()->assigned_agent_id)->toBe($assignedLongAgo->id);
});

it('computes workload for all candidates in one query', function () {
    User::factory()->agent()->count(5)->create();
    $ticket = Ticket::factory()->for($this->category)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    DB::transaction(fn () => app(LeastWorkloadStrategy::class)->pick($ticket));
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // candidates, lock, pick-with-workload: 3 queries regardless of agent count.
    expect($queries)->toHaveCount(3)
        ->and(strtolower($queries->last()))->toContain('sum(case tickets.priority');
});

it('locks candidate agents in id order before reading workload (MySQL)', function () {
    $agents = User::factory()->agent()->count(3)->create();
    $ticket = Ticket::factory()->for($this->category)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    DB::transaction(fn () => app(LeastWorkloadStrategy::class)->pick($ticket));
    $lock = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains(strtolower($q), 'for update'));
    DB::disableQueryLog();

    expect($lock)->not->toBeNull()
        ->and(strtolower($lock))->toContain('order by `id` asc for update');
})->skip(fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'Row locks are only emitted on MySQL.');

it('resolves the strategy from config', function () {
    expect(app(AssignmentStrategy::class))->toBeInstanceOf(LeastWorkloadStrategy::class);

    config(['support.assignment.strategy' => 'round_robin']);
    expect(app(AssignmentStrategy::class))->toBeInstanceOf(RoundRobinStrategy::class);

    config(['support.assignment.strategy' => 'random']);
    expect(fn () => app(AssignmentStrategy::class))->toThrow(InvalidArgumentException::class);
});

it('round-robin picks the least recently assigned agent regardless of workload', function () {
    config(['support.assignment.strategy' => 'round_robin']);
    $recent = User::factory()->agent()->create(['last_assigned_at' => now()->subMinute()]);
    $loadedButOld = User::factory()->agent()->create(['last_assigned_at' => now()->subDay()]);
    Ticket::factory()->count(4)->inProgress()->assigned($loadedButOld)->create();

    $ticket = Ticket::factory()->for($this->category)->create();
    autoAssign($ticket);

    expect($ticket->fresh()->assigned_agent_id)->toBe($loadedButOld->id);
});

it('leaves the ticket unassigned and records the skip once when nobody is available', function () {
    User::factory()->unavailable()->create();
    $ticket = Ticket::factory()->for($this->category)->create();

    autoAssign($ticket);
    autoAssign($ticket); // scheduler retry five minutes later

    expect($ticket->fresh()->assigned_agent_id)->toBeNull()
        ->and($ticket->activities()->where('type', ActivityType::AutoAssignSkipped)->count())->toBe(1);
});

it('does nothing for a ticket that was assigned in the meantime', function () {
    $agent = User::factory()->agent()->create();
    $other = User::factory()->agent()->create();
    $ticket = Ticket::factory()->for($this->category)->create();
    app(ClaimTicket::class)($ticket, $other, $other);

    autoAssign($ticket);

    expect($ticket->fresh()->assigned_agent_id)->toBe($other->id)
        ->and($ticket->activities()->where('type', ActivityType::Assigned)->count())->toBe(1);
});

it('is a unique, retrying queued job', function () {
    $job = new AutoAssignTicket(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('42')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 30, 120]);
});

it('auto-assigns end to end when a ticket is created', function () {
    $agent = User::factory()->agent()->create();

    $ticket = app(CreateTicket::class)(User::factory()->create(), [
        'subject' => 'Refund', 'description' => 'Please refund', 'priority' => 'high', 'category_id' => $this->category->id,
    ]);

    expect($ticket->fresh()->assigned_agent_id)->toBe($agent->id)
        ->and($ticket->activities()->where('type', ActivityType::Assigned)->sole()->actor_id)->toBeNull();
});

/* Scheduler retry ------------------------------------------------------- */

it('re-queues unassigned active tickets, oldest due first', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC'));
    $later = Ticket::factory()->create(['due_at' => now()->addDay()]);
    $sooner = Ticket::factory()->create(['due_at' => now()->addHour()]);
    Ticket::factory()->assigned()->create();
    Ticket::factory()->closed()->create(['assigned_agent_id' => null]);

    $this->artisan('tickets:assign-unassigned')->expectsOutputToContain('2 unassigned ticket(s)')->assertSuccessful();

    $pushed = Queue::pushedJobs()[AutoAssignTicket::class] ?? [];

    expect(collect($pushed)->map(fn ($entry) => $entry['job']->ticketId)->all())->toBe([$sooner->id, $later->id]);
});

it('schedules the retry every five minutes on one server without overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'tickets:assign-unassigned'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
