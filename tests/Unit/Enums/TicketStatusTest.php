<?php

use App\Enums\TicketStatus;

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

it('allows only the defined transitions', function () {
    expect(TicketStatus::Open->canTransitionTo(TicketStatus::InProgress))->toBeTrue()
        ->and(TicketStatus::InProgress->canTransitionTo(TicketStatus::Open))->toBeFalse()
        ->and(TicketStatus::Resolved->canTransitionTo(TicketStatus::Open))->toBeTrue()
        ->and(TicketStatus::Closed->allowedTransitions())->toBe([TicketStatus::Open]);
});

it('never allows a status to transition to itself', function (TicketStatus $status) {
    expect($status->canTransitionTo($status))->toBeFalse();
})->with(TicketStatus::cases());
