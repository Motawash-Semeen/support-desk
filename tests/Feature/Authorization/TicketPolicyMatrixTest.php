<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\Gate;

/*
| Policy matrix: (ticket state) x (actor's relationship to the ticket) x ability.
|
|   A = allowed    D = denied (403)    N = denied as not found (404)    - = not applicable
|
| Columns: admin, assignee (the assigned agent), other agent, owner (customer
| who created it), other customer.
|
| Models are built in memory (no DB): policies only read role, ids, status.
*/

const ACTORS = ['admin', 'assignee', 'other_agent', 'owner', 'other_customer'];

const MATRIX = [
    'open_unassigned' => [
        'view' => 'A - A A N',
        'update' => 'A - D A N',
        'changePriority' => 'A - D D N',
        'changeCategory' => 'A - D D N',
        'assign' => 'A - D D N',
        'accept' => 'D - A D N',
        'release' => 'D - D D N',
        'reply' => 'A - D A N',
        'addInternalNote' => 'A - D D N',
        'resolve' => 'A - D D N',
        'changeStatus' => 'A - D D N',
        'close' => 'A - D D N',
        'reopen' => 'D - D D N',
        'viewInternalNotes' => 'A - A D N',
    ],
    'open_assigned' => [
        'view' => 'A A N A N',
        'update' => 'A A N A N',
        'changePriority' => 'A A N D N',
        'changeCategory' => 'A A N D N',
        'assign' => 'A D N D N',
        'accept' => 'D A A D N',
        'release' => 'A A N D N',
        'reply' => 'A A N A N',
        'addInternalNote' => 'A A N D N',
        'resolve' => 'A A N D N',
        'changeStatus' => 'A A N D N',
        'close' => 'A A N D N',
        'reopen' => 'D D N D N',
        'viewInternalNotes' => 'A A N D N',
    ],
    'in_progress' => [
        'view' => 'A A N A N',
        'update' => 'A A N D N',
        'changePriority' => 'A A N D N',
        'changeCategory' => 'A A N D N',
        'assign' => 'A D N D N',
        'accept' => 'D A A D N',
        'release' => 'A A N D N',
        'reply' => 'A A N A N',
        'addInternalNote' => 'A A N D N',
        'resolve' => 'A A N D N',
        'changeStatus' => 'A A N D N',
        'close' => 'A A N D N',
        'reopen' => 'D D N D N',
        'viewInternalNotes' => 'A A N D N',
    ],
    'resolved' => [
        'view' => 'A A N A N',
        'update' => 'A A N D N',
        'changePriority' => 'A A N D N',
        'changeCategory' => 'A A N D N',
        'assign' => 'D D N D N',
        'accept' => 'D D D D N',
        'release' => 'D D N D N',
        'reply' => 'A A N A N',
        'addInternalNote' => 'A A N D N',
        'resolve' => 'A A N D N',
        'changeStatus' => 'A A N D N',
        'close' => 'A A N A N',
        'reopen' => 'A A N A N',
        'viewInternalNotes' => 'A A N D N',
    ],
    'closed' => [
        'view' => 'A A N A N',
        'update' => 'A A N D N',
        'changePriority' => 'A A N D N',
        'changeCategory' => 'A A N D N',
        'assign' => 'D D N D N',
        'accept' => 'D D D D N',
        'release' => 'D D N D N',
        'reply' => 'D D N D N',
        'addInternalNote' => 'A A N D N',
        'resolve' => 'A A N D N',
        'changeStatus' => 'A A N D N',
        'close' => 'A A N D N',
        'reopen' => 'A D N D N',
        'viewInternalNotes' => 'A A N D N',
    ],
];

dataset('ticket policy matrix', function () {
    foreach (MATRIX as $state => $abilities) {
        foreach ($abilities as $ability => $row) {
            foreach (explode(' ', $row) as $i => $expected) {
                if ($expected !== '-') {
                    yield "{$state} / ".ACTORS[$i]." / {$ability}" => [$state, ACTORS[$i], $ability, $expected];
                }
            }
        }
    }
});

function matrixUsers(): array
{
    $make = fn (int $id, Role $role) => (new User)->forceFill(['id' => $id, 'name' => "u{$id}", 'role' => $role]);

    return [
        'admin' => $make(1, Role::Admin),
        'assignee' => $make(2, Role::Agent),
        'other_agent' => $make(3, Role::Agent),
        'owner' => $make(4, Role::Customer),
        'other_customer' => $make(5, Role::Customer),
    ];
}

function matrixTicket(string $state): Ticket
{
    [$status, $assigned] = match ($state) {
        'open_unassigned' => [TicketStatus::Open, false],
        'open_assigned' => [TicketStatus::Open, true],
        'in_progress' => [TicketStatus::InProgress, true],
        'resolved' => [TicketStatus::Resolved, true],
        'closed' => [TicketStatus::Closed, true],
    };

    return (new Ticket)->forceFill([
        'id' => 100,
        'status' => $status,
        'created_by' => 4,
        'assigned_agent_id' => $assigned ? 2 : null,
    ]);
}

it('authorizes each ability per role and relationship', function (string $state, string $actor, string $ability, string $expected) {
    $response = Gate::forUser(matrixUsers()[$actor])->inspect($ability, matrixTicket($state));

    match ($expected) {
        'A' => expect($response->allowed())->toBeTrue(),
        'D' => expect($response->denied())->toBeTrue()->and($response->status())->toBeNull(),
        'N' => expect($response->denied())->toBeTrue()->and($response->status())->toBe(404),
    };
})->with('ticket policy matrix');

it('treats waiting-for-customer like any other active status', function () {
    $ticket = matrixTicket('in_progress')->forceFill(['status' => TicketStatus::WaitingForCustomer]);
    $users = matrixUsers();

    expect(Gate::forUser($users['assignee'])->allows('release', $ticket))->toBeTrue()
        ->and(Gate::forUser($users['owner'])->allows('update', $ticket))->toBeFalse()
        ->and(Gate::forUser($users['other_customer'])->inspect('reply', $ticket)->status())->toBe(404);
});

it('applies class-level abilities and gates by role', function (string $actor, string $ability, bool $allowed) {
    $user = matrixUsers()[$actor];

    $result = in_array($ability, ['view-dashboard', 'manage-sla-settings'], true)
        ? Gate::forUser($user)->allows($ability)
        : Gate::forUser($user)->allows($ability, Ticket::class);

    expect($result)->toBe($allowed);
})->with([
    ['admin', 'viewAny', true], ['assignee', 'viewAny', true], ['owner', 'viewAny', true],
    ['admin', 'create', true], ['assignee', 'create', true], ['owner', 'create', true],
    ['admin', 'import', true], ['assignee', 'import', false], ['owner', 'import', false],
    ['admin', 'view-dashboard', true], ['assignee', 'view-dashboard', true], ['owner', 'view-dashboard', false],
    ['admin', 'manage-sla-settings', true], ['assignee', 'manage-sla-settings', false], ['owner', 'manage-sla-settings', false],
]);
