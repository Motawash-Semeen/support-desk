<?php

use App\Models\User;
use App\Modules\Assignment\Services\LeastWorkloadStrategy;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Table-driven ordering rules of auto-assignment. The ordering is one SQL
 * query (taken under row locks), so the smallest honest unit includes the
 * database; nothing else (HTTP, events, queue) is involved.
 *
 * Each agent: [open ticket priorities, minutes since last assignment | null].
 * Agents are created in key order, so "a" always has the lower id.
 */

uses(TestCase::class, RefreshDatabase::class);

function pickFor(string $strategy, array $agents): string
{
    $created = [];

    foreach ($agents as $name => [$priorities, $lastAssigned]) {
        $agent = User::factory()->agent()->create([
            'name' => $name,
            'last_assigned_at' => $lastAssigned === null ? null : now()->subMinutes($lastAssigned),
        ]);

        foreach ($priorities as $priority) {
            Ticket::factory()->inProgress()->assigned($agent)->priority($priority)->create();
        }

        $created[$name] = $agent;
    }

    $ticket = Ticket::factory()->unassigned()->create();

    return app($strategy)->pick($ticket)?->name ?? 'nobody';
}

test('least-workload picks by weighted open workload, then never/least recently assigned, then id', function (array $agents, string $expected) {
    expect(pickFor(LeastWorkloadStrategy::class, $agents))->toBe($expected);
})->with([
    'lower workload wins' => [['a' => [[TicketPriority::High], null], 'b' => [[], 5]], 'b'],
    'workload is weighted by priority (critical=3 > low+low=2)' => [['a' => [[TicketPriority::Critical], null], 'b' => [[TicketPriority::Low, TicketPriority::Low], null]], 'b'],
    'equal workload: never-assigned first' => [['a' => [[], 10], 'b' => [[], null]], 'b'],
    'equal workload: least recently assigned next' => [['a' => [[], 10], 'b' => [[], 60]], 'b'],
    'full tie: lowest id' => [['a' => [[], null], 'b' => [[], null]], 'a'],
]);

test('least-workload ignores resolved and closed tickets when weighing workload', function () {
    $busy = User::factory()->agent()->create(['name' => 'busy']);
    $idle = User::factory()->agent()->create(['name' => 'idle', 'last_assigned_at' => now()]);
    Ticket::factory()->count(3)->resolved()->assigned($busy)->create();
    Ticket::factory()->closed()->assigned($busy)->create();

    expect(app(LeastWorkloadStrategy::class)->pick(Ticket::factory()->unassigned()->create())->is($busy))->toBeTrue();
});

test('nobody is picked when no agent is available', function () {
    User::factory()->agent()->unavailable()->create();

    expect(app(LeastWorkloadStrategy::class)->pick(Ticket::factory()->unassigned()->create()))->toBeNull();
});
