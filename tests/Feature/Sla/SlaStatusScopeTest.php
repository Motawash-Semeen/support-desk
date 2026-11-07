<?php

use App\Modules\Sla\Enums\SlaStatus;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;

/*
| The SQL scopes and Ticket::slaStatus() must agree for every ticket,
| including the exact boundaries (due == now, warning == now).
*/

it('agrees between the SQL scopes and the PHP resolver on every boundary', function () {
    $now = CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC');
    $this->travelTo($now);

    $active = fn (array $attributes) => Ticket::factory()->create(['status' => TicketStatus::InProgress] + $attributes);
    $finished = fn (TicketStatus $status, array $attributes) => Ticket::factory()->create(['status' => $status] + $attributes);

    $tickets = [
        $active(['due_at' => $now->addHour(), 'sla_warning_at' => $now->addMinutes(30)]),          // within
        $active(['due_at' => $now->addHour(), 'sla_warning_at' => null]),                         // within, no warning
        $active(['due_at' => $now->addHour(), 'sla_warning_at' => $now]),                         // approaching (warning == now)
        $active(['due_at' => $now, 'sla_warning_at' => $now->subHour()]),                         // approaching (due == now)
        $active(['due_at' => $now->subSecond(), 'sla_warning_at' => $now->subHour()]),            // breached
        $active(['due_at' => null, 'sla_warning_at' => null]),                                    // no status
        Ticket::factory()->create(['status' => TicketStatus::Open, 'due_at' => $now->subDay()]),  // breached (open)
        $finished(TicketStatus::Resolved, ['due_at' => $now, 'resolved_at' => $now->subHour()]),  // met
        $finished(TicketStatus::Resolved, ['due_at' => $now, 'resolved_at' => $now]),             // met (exactly at due)
        $finished(TicketStatus::Closed, ['due_at' => $now, 'resolved_at' => $now->addHour(), 'closed_at' => $now->addHours(2)]), // missed
        $finished(TicketStatus::Closed, ['due_at' => $now, 'resolved_at' => null, 'closed_at' => $now->subMinute()]),            // met via closed_at
        $finished(TicketStatus::Closed, ['due_at' => $now->subDay(), 'resolved_at' => null, 'closed_at' => null]),              // no status
    ];

    $all = Ticket::query()->whereKey(collect($tickets)->pluck('id'))->get();

    foreach (SlaStatus::cases() as $status) {
        $fromSql = Ticket::query()->slaStatus($status)->pluck('id')->sort()->values()->all();
        $fromPhp = $all->filter(fn (Ticket $t) => $t->slaStatus() === $status)->pluck('id')->sort()->values()->all();

        expect($fromSql)->toBe($fromPhp, "Scope and resolver disagree for {$status->value}");
    }

    $counts = collect(SlaStatus::cases())->mapWithKeys(fn ($s) => [$s->value => Ticket::query()->slaStatus($s)->count()])->all();

    expect($counts)->toBe(['within_sla' => 2, 'approaching' => 2, 'breached' => 2, 'met' => 3, 'missed' => 1]);
});

it('keeps the named scopes in line with the resolver', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC'));
    $breached = Ticket::factory()->inProgress()->breached()->create();
    $approaching = Ticket::factory()->approaching()->create();
    $within = Ticket::factory()->create(['due_at' => now()->addDay(), 'sla_warning_at' => now()->addHours(12)]);

    expect(Ticket::query()->slaBreached()->pluck('id')->all())->toBe([$breached->id])
        ->and(Ticket::query()->slaApproaching()->pluck('id')->all())->toBe([$approaching->id])
        ->and(Ticket::query()->withinSla()->pluck('id')->all())->toBe([$within->id])
        ->and($breached->slaStatus())->toBe(SlaStatus::Breached)
        ->and($approaching->slaStatus())->toBe(SlaStatus::Approaching)
        ->and($within->slaStatus())->toBe(SlaStatus::WithinSla);
});
