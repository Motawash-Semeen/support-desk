<?php

use App\Actions\Assignment\AssignTicket;
use App\Actions\Assignment\ClaimTicket;
use App\Actions\Assignment\ReleaseTicket;
use App\Enums\ActivityType;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketReleased;
use App\Events\TicketStatusChanged;
use App\Exceptions\AssignmentConflict;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->agentA = User::factory()->agent()->create(['name' => 'Agent A']);
    $this->agentB = User::factory()->agent()->create(['name' => 'Agent B']);
    $this->admin = User::factory()->admin()->create();
    $this->ticket = Ticket::factory()->create(); // open, unassigned
});

/* ClaimTicket: the compare-and-set primitive --------------------------- */

it('lets exactly one of two agents claim the same ticket (deterministic race)', function () {
    // Both agents act on the same stale in-memory model, as two requests would.
    $staleForB = Ticket::query()->find($this->ticket->id);

    expect(app(ClaimTicket::class)($this->ticket, $this->agentA, $this->agentA))->toBeTrue()
        ->and(app(ClaimTicket::class)($staleForB, $this->agentB, $this->agentB))->toBeFalse();

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

    app(ClaimTicket::class)($this->ticket, $this->agentA);

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

    app(ClaimTicket::class)($this->ticket, $this->agentA);
    app(ClaimTicket::class)($waiting, $this->agentA);

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($waiting->fresh()->status)->toBe(TicketStatus::WaitingForCustomer)
        ->and($waiting->fresh()->assigned_agent_id)->toBe($this->agentA->id);
});

it('does not claim resolved or closed tickets', function (string $state) {
    $ticket = Ticket::factory()->{$state}()->create(['assigned_agent_id' => null]);

    expect(app(ClaimTicket::class)($ticket, $this->agentA))->toBeFalse()
        ->and($ticket->fresh()->assigned_agent_id)->toBeNull();
})->with(['resolved', 'closed']);

it('only assigns agents', function () {
    expect(fn () => app(ClaimTicket::class)($this->ticket, $this->admin))->toThrow(InvalidArgumentException::class);
});

it('dispatches TicketAssigned and the open-to-in-progress status change', function () {
    Event::fake([TicketAssigned::class, TicketStatusChanged::class]);

    app(ClaimTicket::class)($this->ticket, $this->agentA, null);

    Event::assertDispatched(TicketAssigned::class, fn ($e) => $e->agent->is($this->agentA) && $e->actor === null);
    Event::assertDispatched(TicketStatusChanged::class, fn ($e) => $e->from === TicketStatus::Open && $e->to === TicketStatus::InProgress);
});

/* AssignTicket: admin assignment guarded by the assignee the admin saw ------ */

it('lets an admin assign an unassigned ticket', function () {
    app(AssignTicket::class)($this->admin, $this->ticket, $this->agentA, null);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentA->id)
        ->and($this->ticket->activities()->sole()->type)->toBe(ActivityType::Assigned);
});

it('reassigns and records old and new agent', function () {
    app(ClaimTicket::class)($this->ticket, $this->agentA);

    app(AssignTicket::class)($this->admin, $this->ticket, $this->agentB, $this->agentA->id);

    $activity = $this->ticket->activities()->where('type', ActivityType::Reassigned)->sole();

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentB->id)
        ->and($activity->old_values['assigned_agent_id'])->toBe($this->agentA->id)
        ->and($activity->new_values['assigned_agent_id'])->toBe($this->agentB->id)
        ->and($activity->description)->toBe("Reassigned from Agent A to Agent B by {$this->admin->name}");
});

it('reports a conflict instead of clobbering a concurrent reassignment by another admin', function () {
    $agentC = User::factory()->agent()->create();
    app(ClaimTicket::class)($this->ticket, $this->agentA);
    $secondAdmin = User::factory()->admin()->create();

    // Both admins saw agent A. The first one wins ...
    app(AssignTicket::class)($this->admin, $this->ticket, $this->agentB, $this->agentA->id);

    // ... the second one's guarded UPDATE matches zero rows.
    expect(fn () => app(AssignTicket::class)($secondAdmin, $this->ticket, $agentC, $this->agentA->id))
        ->toThrow(AssignmentConflict::class);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentB->id);
});

it('reports a conflict when an admin assigns a ticket an agent accepted meanwhile', function () {
    app(ClaimTicket::class)($this->ticket, $this->agentA, $this->agentA);

    expect(fn () => app(AssignTicket::class)($this->admin, $this->ticket, $this->agentB, null))
        ->toThrow(AssignmentConflict::class);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentA->id);
});

/* ReleaseTicket ------------------------------------------------------- */

it('releases a ticket back to the open queue', function () {
    Event::fake([TicketReleased::class]);
    app(ClaimTicket::class)($this->ticket, $this->agentA);

    app(ReleaseTicket::class)($this->agentA, $this->ticket->fresh());

    $ticket = $this->ticket->fresh();

    expect($ticket->assigned_agent_id)->toBeNull()
        ->and($ticket->assigned_at)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->activities()->where('type', ActivityType::Released)->sole()->old_values['assigned_agent_id'])->toBe($this->agentA->id);

    Event::assertDispatched(TicketReleased::class);
});

it('never undoes a newer assignment when releasing', function () {
    app(ClaimTicket::class)($this->ticket, $this->agentA);
    $seenByA = $this->ticket->fresh();
    app(AssignTicket::class)($this->admin, $this->ticket, $this->agentB, $this->agentA->id);

    expect(fn () => app(ReleaseTicket::class)($this->agentA, $seenByA))->toThrow(AssignmentConflict::class);

    expect($this->ticket->fresh()->assigned_agent_id)->toBe($this->agentB->id);
});
