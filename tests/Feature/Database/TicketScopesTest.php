<?php

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('splits tickets into active and closed-or-resolved', function () {
    $open = Ticket::factory()->create();
    $inProgress = Ticket::factory()->inProgress()->create();
    $waiting = Ticket::factory()->waitingForCustomer()->create();
    $resolved = Ticket::factory()->resolved()->create();
    $closed = Ticket::factory()->closed()->create();

    expect(Ticket::query()->active()->pluck('id')->all())
        ->toEqualCanonicalizing([$open->id, $inProgress->id, $waiting->id])
        ->and(Ticket::query()->closedOrResolved()->pluck('id')->all())
        ->toEqualCanonicalizing([$resolved->id, $closed->id]);
});

it('filters unassigned tickets and tickets assigned to an agent', function () {
    $agent = User::factory()->agent()->create();
    $mine = Ticket::factory()->assigned($agent)->create();
    Ticket::factory()->assigned()->create();
    $unassigned = Ticket::factory()->create();

    expect(Ticket::query()->unassigned()->pluck('id')->all())->toBe([$unassigned->id])
        ->and(Ticket::query()->assignedTo($agent)->pluck('id')->all())->toBe([$mine->id])
        ->and(Ticket::query()->assignedTo($agent->id)->count())->toBe(1);
});

it('limits customers to their own tickets and lets staff see all', function () {
    $customer = User::factory()->create();
    $own = Ticket::factory()->for($customer, 'creator')->create();
    Ticket::factory()->count(2)->create();

    $agent = User::factory()->agent()->create();
    $admin = User::factory()->admin()->create();

    expect(Ticket::query()->visibleTo($customer)->pluck('id')->all())->toBe([$own->id])
        ->and(Ticket::query()->visibleTo($agent)->count())->toBe(3)
        ->and(Ticket::query()->visibleTo($admin)->count())->toBe(3);
});

it('finds breached and approaching tickets among active tickets only', function () {
    $breached = Ticket::factory()->inProgress()->breached()->create();
    $approaching = Ticket::factory()->approaching()->create();
    $onTrack = Ticket::factory()->onTrack()->create();
    Ticket::factory()->breached()->resolved()->create(); // overdue but finished: not breached

    expect(Ticket::query()->slaBreached()->pluck('id')->all())->toBe([$breached->id])
        ->and(Ticket::query()->slaApproaching()->pluck('id')->all())->toBe([$approaching->id])
        ->and($onTrack->isSlaBreached())->toBeFalse();
});

it('searches subject and description', function () {
    $match = Ticket::factory()->create(['subject' => 'Printer jammed on floor three']);
    Ticket::factory()->create(['subject' => 'Password reset', 'description' => 'Cannot log in']);

    $query = Ticket::query()->search('printer');

    if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        // InnoDB FULLTEXT does not see rows from the open test transaction, so
        // assert the index-backed SQL instead of the result.
        expect(strtolower($query->toSql()))->toContain('match (`subject`, `description`) against');
    } else {
        expect($query->pluck('id')->all())->toBe([$match->id]);
    }

    expect(Ticket::query()->search('   ')->count())->toBe(2);
});

it('builds consistent workflow timestamps in the factory', function () {
    $closed = Ticket::factory()->closed()->create();

    expect($closed->status)->toBe(TicketStatus::Closed)
        ->and($closed->assigned_agent_id)->not->toBeNull()
        ->and($closed->assigned_at->gte($closed->created_at))->toBeTrue()
        ->and($closed->first_response_at->gte($closed->assigned_at))->toBeTrue()
        ->and($closed->resolved_at->gte($closed->first_response_at))->toBeTrue()
        ->and($closed->closed_at->gte($closed->resolved_at))->toBeTrue()
        ->and($closed->due_at->equalTo($closed->created_at->copy()->addMinutes($closed->sla_minutes)))->toBeTrue();
});
