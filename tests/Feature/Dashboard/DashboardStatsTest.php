<?php

use App\Enums\ActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Dashboard\DashboardStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| Known fixture (now = Sunday 2026-11-08 12:00 Dhaka):
|   A  open, unassigned, critical, on track
|   B  in progress, agent 1, high, BREACHED, escalated
|   C  in progress, agent 1, medium, APPROACHING
|   D  waiting, agent 2, low, on track
|   E  resolved today, agent 2
|   F  closed 3 days ago, agent 1
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC'));

    $this->admin = User::factory()->admin()->create();
    $this->agent1 = User::factory()->agent()->create(['name' => 'Agent One']);
    $this->agent2 = User::factory()->agent()->create(['name' => 'Agent Two']);

    $onTrack = ['due_at' => now()->addDay(), 'sla_warning_at' => now()->addHours(12)];

    $this->a = Ticket::factory()->priority(TicketPriority::Critical)->create($onTrack);
    $this->b = Ticket::factory()->priority(TicketPriority::High)->create(['status' => TicketStatus::InProgress, 'assigned_agent_id' => $this->agent1->id, 'due_at' => now()->subHour(), 'sla_warning_at' => now()->subHours(2), 'is_escalated' => true, 'escalation_level' => 1]);
    $this->c = Ticket::factory()->priority(TicketPriority::Medium)->create(['status' => TicketStatus::InProgress, 'assigned_agent_id' => $this->agent1->id, 'due_at' => now()->addHour(), 'sla_warning_at' => now()->subMinutes(10)]);
    $this->d = Ticket::factory()->priority(TicketPriority::Low)->create(['status' => TicketStatus::WaitingForCustomer, 'assigned_agent_id' => $this->agent2->id] + $onTrack);
    $this->e = Ticket::factory()->create(['status' => TicketStatus::Resolved, 'assigned_agent_id' => $this->agent2->id, 'resolved_at' => now()->subHour()]);
    $this->f = Ticket::factory()->create(['status' => TicketStatus::Closed, 'assigned_agent_id' => $this->agent1->id, 'resolved_at' => now()->subDays(3), 'closed_at' => now()->subDays(3)]);

    TicketActivity::factory()->for($this->b)->create(['type' => ActivityType::Escalated, 'description' => 'Escalated to level 1 (SLA breached)']);
});

it('computes the admin (global) numbers', function () {
    $stats = app(DashboardStatsService::class)->for($this->admin);

    expect($stats->scope)->toBe('global')
        ->and($stats->totalActive)->toBe(4)
        ->and($stats->unassignedActive)->toBe(1)
        ->and($stats->criticalActive)->toBe(1)
        ->and($stats->slaBreachedActive)->toBe(1)
        ->and($stats->approachingActive)->toBe(1)
        ->and($stats->resolvedTotal)->toBe(2)
        ->and($stats->resolvedToday)->toBe(1)
        ->and($stats->escalatedActive)->toBe(1)
        ->and($stats->byPriority)->toBe(['critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 1])
        ->and($stats->byAgent)->toBe([
            ['id' => $this->agent1->id, 'name' => 'Agent One', 'active' => 2, 'breached' => 1],
            ['id' => $this->agent2->id, 'name' => 'Agent Two', 'active' => 1, 'breached' => 0],
        ])
        ->and($stats->recentEscalations)->toHaveCount(1)
        ->and($stats->recentEscalations[0]['ticket_number'])->toBe($this->b->ticket_number)
        ->and($stats->recentEscalations[0]['agent'])->toBe('Agent One');
});

it('limits an agent to their own tickets plus the unassigned queue', function () {
    $stats = app(DashboardStatsService::class)->for($this->agent2);

    // A (unassigned), D (own, active), E (own, resolved today)
    expect($stats->scope)->toBe('agent')
        ->and($stats->totalActive)->toBe(2)
        ->and($stats->unassignedActive)->toBe(1)
        ->and($stats->criticalActive)->toBe(1)
        ->and($stats->slaBreachedActive)->toBe(0)
        ->and($stats->approachingActive)->toBe(0)
        ->and($stats->resolvedTotal)->toBe(1)
        ->and($stats->resolvedToday)->toBe(1)
        ->and($stats->escalatedActive)->toBe(0)
        ->and($stats->byAgent)->toBe([['id' => $this->agent2->id, 'name' => 'Agent Two', 'active' => 1, 'breached' => 0]])
        ->and($stats->recentEscalations)->toBe([]);
});

it('builds everything in a handful of queries: two aggregate queries for the eight counters', function () {
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(DashboardStatsService::class)->for($this->admin);

    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => strtolower(str_replace(['`', '"'], '', $sql)));
    DB::disableQueryLog();

    // active counters, finished counters, by priority, by agent, escalations + 2 eager loads.
    // Each counter query is restricted by status, so neither scans the whole table.
    expect($queries)->toHaveCount(7)
        ->and(substr_count($queries[0], 'sum(case when'))->toBe(6)
        ->and($queries[0])->toContain('where tickets.status in')
        ->and(substr_count($queries[1], 'sum(case when'))->toBe(2)
        ->and($queries[1])->toContain('where tickets.status in');
});

it('serves repeat visits from a 30-second cache per scope', function () {
    $service = app(DashboardStatsService::class);
    $service->for($this->admin);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $service->for($this->admin);
    $cachedQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($cachedQueries)->toBe(0)
        ->and(DashboardStatsService::cacheKey($this->admin))->toBe('dashboard:admin')
        ->and(DashboardStatsService::cacheKey($this->agent1))->toBe("dashboard:agent:{$this->agent1->id}");

    Ticket::factory()->create(['due_at' => now()->addDay(), 'sla_warning_at' => now()->addHours(12)]);
    expect($service->for($this->admin)->totalActive)->toBe(4); // still cached (accepted staleness)

    $this->travel(31)->seconds();
    expect($service->for($this->admin)->totalActive)->toBe(5);
});

it('renders stat cards that link to the filtered ticket list', function () {
    $this->actingAs($this->admin)
        ->get(route('staff.dashboard'))
        ->assertOk()
        ->assertSee(route('tickets.index', ['scope' => 'active', 'sla_status' => 'breached']))
        ->assertSee(route('tickets.index', ['scope' => 'active', 'assigned_agent_id' => 'unassigned']))
        ->assertSee('Agent One')
        ->assertSee($this->b->ticket_number)
        ->assertSee('Recent escalations');
});

it('is staff only', function () {
    $this->actingAs(User::factory()->create())->get(route('staff.dashboard'))->assertForbidden();
});
