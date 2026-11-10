<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Assignment\AssignmentService;
use App\Modules\Notifications\TicketNotification;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->agentA = User::factory()->agent()->create(['name' => 'Agent A']);
    $this->agentB = User::factory()->agent()->create(['name' => 'Agent B']);
    $this->admin = User::factory()->admin()->create();
    $this->ticket = Ticket::factory()->create(); // open, unassigned
});

/* claim(): the compare-and-set primitive --------------------------- */

it('lets exactly one of two agents claim the same ticket (deterministic race)', function () {
    // Both agents act on the same stale in-memory model, as two requests would.
    $staleForB = Ticket::query()->find($this->ticket->id);

    expect(app(AssignmentService::class)->claim($this->ticket, $this->agentA, $this->agentA))->toBeTrue()
        ->and(app(AssignmentService::class)->claim($staleForB, $this->agentB, $this->agentB))->toBeFalse();

    $ticket = $this->ticket->fresh();

    expect($ticket->assigned_agent_id)->toBe($this->agentA->id)
        ->and($ticket->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->activities()->where('type', ActivityType::Assigned)->count())->toBe(1)
        ->and($this->agentA->fresh()->last_assigned_at)->not->toBeNull()
        ->and($this->agentB->fresh()->last_assigned_at)->toBeNull();
});

it('claims with ONE conditional UPDATE whose WHERE is the guard', function () {
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    app(AssignmentService::class)->claim($this->ticket, $this->agentA);

    $updates = collect($sql)
        ->map(fn (string $s) => strtolower(str_replace(['`', '"'], '', $s)))
        ->filter(fn (string $s) => str_starts_with($s, 'update tickets'))
        ->values();

    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toContain('set assigned_agent_id = ?, assigned_at = ?')
        ->and($updates[0])->toContain("status = case when status = 'open' then 'in_progress' else status end")
        ->and($updates[0])->toEndWith('where tickets.id = ? and assigned_agent_id is null and status in (?, ?, ?)');
});

it('moves open to in progress but keeps waiting-for-customer as it is', function () {
    $waiting = Ticket::factory()->create(['status' => TicketStatus::WaitingForCustomer]);

    app(AssignmentService::class)->claim($this->ticket, $this->agentA);
    app(AssignmentService::class)->claim($waiting, $this->agentA);

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($waiting->fresh()->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($waiting->fresh()->assigned_agent_id)->toBe($this->agentA->id);
});

it('does not claim resolved or closed tickets', function (string $state) {
    $ticket = Ticket::factory()->{$state}()->create(['assigned_agent_id' => null]);

    expect(app(AssignmentService::class)->claim($ticket, $this->agentA))->toBeFalse()
        ->and($ticket->fresh()->assigned_agent_id)->toBeNull();
})->with(['resolved', 'closed']);

it('only assigns agents', function () {
    expect(fn () => app(AssignmentService::class)->claim($this->ticket, $this->admin))->toThrow(InvalidArgumentException::class);
});

it('notifies the agent and moves an open ticket to in progress', function () {
    Notification::fake();

    app(AssignmentService::class)->claim($this->ticket, $this->agentA, null);

    Notification::assertSentTo($this->agentA, TicketNotification::class, ofKind('assigned_to_you'));
    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress);
});

/* assign(): admin assignment guarded by the assignee the admin saw ------ */

it('lets an admin assign an unassigned ticket', function () {
    app(AssignmentService::class)->assign($this->admin, $this->ticket, $this->agentA, null);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentA->id)
        ->and($this->ticket->activities()->sole()->type)->toBe(ActivityType::Assigned);
});

it('reassigns and records old and new agent', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->agentA);

    app(AssignmentService::class)->assign($this->admin, $this->ticket, $this->agentB, $this->agentA->id);

    $activity = $this->ticket->activities()->where('type', ActivityType::Reassigned)->sole();

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentB->id)
        ->and($activity->old_values['assigned_agent_id'])->toBe($this->agentA->id)
        ->and($activity->new_values['assigned_agent_id'])->toBe($this->agentB->id)
        ->and($activity->description)->toBe("Reassigned from Agent A to Agent B by {$this->admin->name}");
});

it('reports a conflict instead of clobbering a concurrent reassignment by another admin', function () {
    $agentC = User::factory()->agent()->create();
    app(AssignmentService::class)->claim($this->ticket, $this->agentA);
    $secondAdmin = User::factory()->admin()->create();

    // Both admins saw agent A. The first one wins ...
    app(AssignmentService::class)->assign($this->admin, $this->ticket, $this->agentB, $this->agentA->id);

    // ... the second one's guarded UPDATE matches zero rows.
    expect(fn () => app(AssignmentService::class)->assign($secondAdmin, $this->ticket, $agentC, $this->agentA->id))
        ->toThrow(HttpException::class);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentB->id);
});

it('reports a conflict when an admin assigns a ticket an agent accepted meanwhile', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->agentA, $this->agentA);

    expect(fn () => app(AssignmentService::class)->assign($this->admin, $this->ticket, $this->agentB, null))
        ->toThrow(HttpException::class);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentA->id);
});

/* release() ------------------------------------------------------------- */

it('releases a ticket back to the open queue', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->agentA);

    app(AssignmentService::class)->release($this->agentA, $this->ticket->fresh());

    $ticket = $this->ticket->fresh();

    expect($ticket->assigned_agent_id)->toBeNull()
        ->and($ticket->assigned_at)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->activities()->where('type', ActivityType::Released)->sole()->old_values['assigned_agent_id'])->toBe($this->agentA->id);
});

it('never undoes a newer assignment when releasing', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->agentA);
    $seenByA = $this->ticket->fresh();
    app(AssignmentService::class)->assign($this->admin, $this->ticket, $this->agentB, $this->agentA->id);

    expect(fn () => app(AssignmentService::class)->release($this->agentA, $seenByA))->toThrow(HttpException::class);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentB->id);
});
