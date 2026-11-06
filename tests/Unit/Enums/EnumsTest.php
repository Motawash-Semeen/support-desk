<?php

use App\Enums\ActivityType;
use App\Enums\ReplyType;
use App\Enums\Role;
use App\Enums\TicketPriority;

it('orders priorities by urgency', function () {
    $sorted = collect(TicketPriority::cases())
        ->sortByDesc(fn (TicketPriority $p) => $p->sortWeight())
        ->values()
        ->all();

    expect($sorted)->toBe([
        TicketPriority::Critical,
        TicketPriority::High,
        TicketPriority::Medium,
        TicketPriority::Low,
    ]);
});

it('marks admins and agents as staff', function () {
    expect(Role::Admin->isStaff())->toBeTrue()
        ->and(Role::Agent->isStaff())->toBeTrue()
        ->and(Role::Customer->isStaff())->toBeFalse();
});

it('shows only public replies to customers', function () {
    expect(ReplyType::Public->isVisibleToCustomer())->toBeTrue()
        ->and(ReplyType::InternalNote->isVisibleToCustomer())->toBeFalse();
});

it('uses the string values stored in the database', function () {
    expect(ReplyType::InternalNote->value)->toBe('internal_note')
        ->and(Role::Customer->value)->toBe('customer')
        ->and(ActivityType::SlaBreached->value)->toBe('sla_breached');
});
