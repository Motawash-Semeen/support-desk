<?php

use App\Enums\Role;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Tickets\Enums\TicketPriority;

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

it('uses the string values stored in the database', function () {
    expect(ReplyType::InternalNote->value)->toBe('internal_note')
        ->and(Role::Customer->value)->toBe('customer')
        ->and(ActivityType::SlaBreached->value)->toBe('sla_breached');
});
