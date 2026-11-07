<?php

use App\Modules\Tickets\Enums\TicketStatus;

it('treats open, in progress and waiting as active', function (TicketStatus $status, bool $active) {
    expect($status->isActive())->toBe($active);
})->with([
    [TicketStatus::Open, true],
    [TicketStatus::InProgress, true],
    [TicketStatus::WaitingForCustomer, true],
    [TicketStatus::Resolved, false],
    [TicketStatus::Closed, false],
]);

it('exposes active and finished values that partition all statuses', function () {
    $all = array_column(TicketStatus::cases(), 'value');

    expect(array_merge(TicketStatus::activeValues(), TicketStatus::finishedValues()))
        ->toEqualCanonicalizing($all);
});

it('encodes the transition map', function (TicketStatus $from, array $to) {
    expect($from->allowedTransitions())->toEqualCanonicalizing($to);
})->with([
    'open' => [TicketStatus::Open, [TicketStatus::InProgress, TicketStatus::WaitingForCustomer, TicketStatus::Resolved]],
    'in progress' => [TicketStatus::InProgress, [TicketStatus::WaitingForCustomer, TicketStatus::Resolved, TicketStatus::Open]],
    'waiting' => [TicketStatus::WaitingForCustomer, [TicketStatus::InProgress, TicketStatus::Resolved, TicketStatus::Closed, TicketStatus::Open]],
    'resolved' => [TicketStatus::Resolved, [TicketStatus::Closed, TicketStatus::InProgress]],
    'closed' => [TicketStatus::Closed, []],
]);

it('checks every pair of statuses against the map', function (TicketStatus $from, TicketStatus $to) {
    expect($from->canTransitionTo($to))->toBe(in_array($to, $from->allowedTransitions(), true));
})->with(TicketStatus::cases())->with(TicketStatus::cases());

it('lets only an admin reopen a closed ticket, and only to in progress', function () {
    expect(TicketStatus::Closed->canTransitionTo(TicketStatus::InProgress))->toBeFalse()
        ->and(TicketStatus::Closed->canTransitionTo(TicketStatus::InProgress, byAdmin: true))->toBeTrue()
        ->and(TicketStatus::Closed->canTransitionTo(TicketStatus::Open, byAdmin: true))->toBeFalse()
        ->and(TicketStatus::reopenTarget())->toBe(TicketStatus::InProgress);
});

it('never allows a status to transition to itself', function (TicketStatus $status) {
    expect($status->canTransitionTo($status, byAdmin: true))->toBeFalse();
})->with(TicketStatus::cases());

it('does not let open or in-progress tickets close without resolving', function () {
    expect(TicketStatus::Open->canTransitionTo(TicketStatus::Closed))->toBeFalse()
        ->and(TicketStatus::InProgress->canTransitionTo(TicketStatus::Closed))->toBeFalse();
});
