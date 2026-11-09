<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Activity\Services\TicketActivityRecorder;
use App\Modules\Assignment\Actions\AssignTicket;
use App\Modules\Assignment\Actions\ClaimTicket;
use App\Modules\Assignment\Actions\ReleaseTicket;
use App\Modules\Assignment\Jobs\AutoAssignTicket;
use App\Modules\Assignment\Services\LeastWorkloadStrategy;
use App\Modules\Conversation\Actions\AddReply;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Sla\Actions\EscalateTicket;
use App\Modules\Sla\Enums\EscalationKind;
use App\Modules\Tickets\Actions\ChangeTicketStatus;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Actions\UpdateTicket;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Exceptions\InvalidTicketTransition;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/*
| Every Action writes exactly the history it should: one activity per business
| change, with correct old/new values. Two deliberate pairs: a priority change
| and a reopen also move the deadline, which is its own sla_recalculated entry.
*/

beforeEach(function () {
    Notification::fake();
    config(['support.assignment.auto_on_create' => false]);

    $this->customer = User::factory()->create(['name' => 'Cara Customer']);
    $this->agent = User::factory()->agent()->create(['name' => 'Agent B']);
    $this->admin = User::factory()->admin()->create(['name' => 'Admin A']);
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->assigned($this->agent)->priority(TicketPriority::Low)->create(['created_at' => now()]);
});

/** Activities written by $act, oldest first. */
function activitiesDuring(Closure $act): Collection
{
    $before = TicketActivity::query()->max('id') ?? 0;
    $act();

    return TicketActivity::query()->where('id', '>', $before)->orderBy('id')->get();
}

it('CreateTicket writes one ticket_created entry', function () {
    $category = Category::factory()->create();

    $log = activitiesDuring(fn () => app(CreateTicket::class)($this->customer, [
        'subject' => 'VPN', 'description' => 'Down', 'priority' => 'high', 'category_id' => $category->id,
    ]));

    expect($log->pluck('type')->all())->toBe([ActivityType::TicketCreated])
        ->and($log[0]->actor_id)->toBe($this->customer->id)
        ->and($log[0]->old_values)->toBeNull()
        ->and($log[0]->new_values)->toBeJsonObject(['status' => 'open', 'priority' => 'high', 'category_id' => $category->id]);
});

it('UpdateTicket writes one entry per edited field', function (array $input, ActivityType $type, string $field) {
    $old = $this->ticket->getRawOriginal($field);

    $log = activitiesDuring(fn () => app(UpdateTicket::class)($this->admin, $this->ticket, $input));

    expect($log->pluck('type')->all())->toBe([$type])
        ->and($log[0]->old_values)->toBe([$field => $old])
        ->and($log[0]->new_values)->toBe([$field => $this->ticket->fresh()->getRawOriginal($field)]);
})->with([
    'subject' => [['subject' => 'Renamed'], ActivityType::TicketUpdated, 'subject'],
    'description' => [['description' => 'More detail'], ActivityType::TicketUpdated, 'description'],
]);

it('UpdateTicket logs a category change with names', function () {
    $billing = Category::factory()->create(['name' => 'Billing']);

    $log = activitiesDuring(fn () => app(UpdateTicket::class)($this->admin, $this->ticket, ['category_id' => $billing->id]));

    expect($log->pluck('type')->all())->toBe([ActivityType::CategoryChanged])
        ->and($log[0]->description)->toEndWith('to Billing')
        ->and($log[0]->new_values)->toBe(['category_id' => $billing->id]);
});

it('a priority change logs priority_changed and the SLA move', function () {
    $log = activitiesDuring(fn () => app(UpdateTicket::class)($this->admin, $this->ticket, ['priority' => 'high']));

    expect($log->pluck('type')->all())->toBe([ActivityType::PriorityChanged, ActivityType::SlaRecalculated])
        ->and($log[0]->description)->toBe('Priority changed from Low to High')
        ->and($log[0]->old_values)->toBe(['priority' => 'low'])
        ->and($log[0]->new_values)->toBe(['priority' => 'high']);
});

it('status changes write one status_changed entry', function () {
    $log = activitiesDuring(fn () => app(ChangeTicketStatus::class)($this->agent, $this->ticket, TicketStatus::WaitingForCustomer));

    expect($log->pluck('type')->all())->toBe([ActivityType::StatusChanged])
        ->and($log[0]->description)->toBe('Status changed from In progress to Waiting for customer')
        ->and($log[0]->old_values)->toBe(['status' => 'in_progress'])
        ->and($log[0]->new_values)->toBe(['status' => 'waiting_for_customer']);

    $log = activitiesDuring(fn () => app(ChangeTicketStatus::class)($this->agent, $this->ticket, TicketStatus::Resolved, 'Fixed'));
    expect($log->pluck('type')->all())->toBe([ActivityType::StatusChanged])
        ->and($log[0]->new_values)->toBeJsonObject(['status' => 'resolved', 'reason' => 'Fixed']);
});

it('a reopen logs reopened and the fresh SLA', function () {
    app(ChangeTicketStatus::class)($this->agent, $this->ticket, TicketStatus::Resolved);

    $log = activitiesDuring(fn () => app(ChangeTicketStatus::class)($this->customer, $this->ticket, TicketStatus::reopenTarget()));

    expect($log->pluck('type')->all())->toBe([ActivityType::Reopened, ActivityType::SlaRecalculated])
        ->and($log[0]->old_values)->toBe(['status' => 'resolved'])
        ->and($log[0]->new_values)->toBe(['status' => 'in_progress']);
});

it('assignment actions write one entry each with old and new agent', function () {
    $open = Ticket::factory()->for($this->customer, 'creator')->create();
    $other = User::factory()->agent()->create(['name' => 'Agent C']);

    $claim = activitiesDuring(fn () => app(ClaimTicket::class)($open, $this->agent, $this->agent));
    expect($claim->pluck('type')->all())->toBe([ActivityType::Assigned])
        ->and($claim[0]->description)->toBe('Accepted by Agent B')
        ->and($claim[0]->old_values)->toBeJsonObject(['assigned_agent_id' => null, 'status' => 'open'])
        ->and($claim[0]->new_values)->toBeJsonObject(['assigned_agent_id' => $this->agent->id, 'status' => 'in_progress']);

    $reassign = activitiesDuring(fn () => app(AssignTicket::class)($this->admin, $open, $other, $this->agent->id));
    expect($reassign->pluck('type')->all())->toBe([ActivityType::Reassigned])
        ->and($reassign[0]->description)->toBe('Reassigned from Agent B to Agent C by Admin A')
        ->and($reassign[0]->old_values['assigned_agent_id'])->toBe($this->agent->id)
        ->and($reassign[0]->new_values['assigned_agent_id'])->toBe($other->id);

    $release = activitiesDuring(fn () => app(ReleaseTicket::class)($other, $open->fresh()));
    expect($release->pluck('type')->all())->toBe([ActivityType::Released])
        ->and($release[0]->new_values)->toBeJsonObject(['assigned_agent_id' => null, 'status' => 'open']);
});

it('an admin assignment reads "Assigned to X by Y"', function () {
    $open = Ticket::factory()->for($this->customer, 'creator')->create();

    $log = activitiesDuring(fn () => app(AssignTicket::class)($this->admin, $open, $this->agent, null));

    expect($log->pluck('type')->all())->toBe([ActivityType::Assigned])
        ->and($log[0]->description)->toBe('Assigned to Agent B by Admin A');
});

it('replies write one entry of the right type', function (string $who, ReplyType $type, ActivityType $expected) {
    $actor = $who === 'agent' ? $this->agent : $this->customer;

    $log = activitiesDuring(fn () => app(AddReply::class)($actor, $this->ticket, 'Hello', $type));

    expect($log->pluck('type')->all())->toBe([$expected]);
})->with([
    'customer reply' => ['customer', ReplyType::Public, ActivityType::CustomerReplied],
    'agent reply' => ['agent', ReplyType::Public, ActivityType::AgentReplied],
    'internal note' => ['agent', ReplyType::InternalNote, ActivityType::InternalNoteAdded],
]);

it('system actions write entries with no actor', function () {
    $orphan = Ticket::factory()->for($this->customer, 'creator')->create();
    User::query()->whereKey($this->agent->id)->update(['is_available' => false]);

    $skip = activitiesDuring(fn () => (new AutoAssignTicket($orphan->id))->handle(
        app(LeastWorkloadStrategy::class), app(ClaimTicket::class), app(TicketActivityRecorder::class),
    ));

    $breached = Ticket::factory()->inProgress()->breached()->create();
    $sla = activitiesDuring(fn () => app(EscalateTicket::class)($breached->id, EscalationKind::Breach));

    // A breach is two facts: the SLA was missed, and the ticket escalated.
    expect($skip->pluck('type')->all())->toBe([ActivityType::AutoAssignSkipped])
        ->and($skip[0]->actor_id)->toBeNull()
        ->and($sla->pluck('type')->all())->toBe([ActivityType::SlaBreached, ActivityType::Escalated])
        ->and($sla->pluck('actor_id')->filter()->all())->toBe([]);
});

it('records no history when the action changes nothing or rolls back', function () {
    expect(activitiesDuring(fn () => app(UpdateTicket::class)($this->admin, $this->ticket, ['subject' => $this->ticket->subject])))->toBeEmpty();

    expect(activitiesDuring(function () {
        try {
            app(ChangeTicketStatus::class)($this->agent, $this->ticket, TicketStatus::Closed); // illegal
        } catch (InvalidTicketTransition) {
        }
    }))->toBeEmpty();
});
