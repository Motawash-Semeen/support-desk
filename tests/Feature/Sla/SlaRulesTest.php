<?php

use App\Modules\Notifications\TicketNotifier;
use App\Modules\Sla\Models\BusinessHour;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Enums\TicketPriority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function freshSla(): SlaService
{
    // A new instance has no in-memory memo, so it reads from the cache.
    return new SlaService(new TicketNotifier);
}

it('falls back to config/support.php when the tables are empty', function () {
    $rules = freshSla();

    expect($rules->policyFor(TicketPriority::Critical)['id'])->toBeNull()
        ->and($rules->policyFor(TicketPriority::Critical)['minutes'])->toBe(120)
        ->and($rules->policyFor(TicketPriority::Critical)['warning_threshold'])->toBe(0.8)
        ->and($rules->calendar()->timezone())->toBe('Asia/Dhaka')
        ->and($rules->calendar()->isWorkingMoment(CarbonImmutable::parse('2026-11-08 10:00', 'Asia/Dhaka')))->toBeTrue()
        ->and($rules->calendar()->isWorkingMoment(CarbonImmutable::parse('2026-11-06 10:00', 'Asia/Dhaka')))->toBeFalse();
});

it('uses the active policy row and ignores inactive ones', function () {
    $high = SlaPolicy::factory()->forPriority(TicketPriority::High)->create(['resolution_minutes' => 300, 'warning_threshold' => 0.5]);
    SlaPolicy::factory()->forPriority(TicketPriority::Low)->inactive()->create(['resolution_minutes' => 10]);

    $rules = freshSla();

    expect($rules->policyFor(TicketPriority::High)['id'])->toBe($high->id)
        ->and($rules->policyFor(TicketPriority::High)['minutes'])->toBe(300)
        ->and($rules->policyFor(TicketPriority::High)['warning_threshold'])->toBe(0.5)
        ->and($rules->policyFor(TicketPriority::Low)['id'])->toBeNull()
        ->and($rules->policyFor(TicketPriority::Low)['minutes'])->toBe(2880);
});

it('loads the rules once and serves later reads from the cache', function () {
    SlaPolicy::factory()->forPriority(TicketPriority::High)->create();
    freshSla()->policyFor(TicketPriority::High); // warm the cache

    DB::flushQueryLog();
    DB::enableQueryLog();
    $rules = freshSla();
    $rules->policyFor(TicketPriority::High);
    $rules->calendar();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0);
});

it('flushes the cache when a policy, business hour or holiday changes', function () {
    $policy = SlaPolicy::factory()->forPriority(TicketPriority::High)->create(['resolution_minutes' => 480]);
    expect(freshSla()->policyFor(TicketPriority::High)['minutes'])->toBe(480);

    $policy->update(['resolution_minutes' => 240]);
    expect(freshSla()->policyFor(TicketPriority::High)['minutes'])->toBe(240);

    Holiday::factory()->create(['date' => '2026-11-08']);
    expect(freshSla()->calendar()->isWorkingMoment(CarbonImmutable::parse('2026-11-08 10:00', 'Asia/Dhaka')))->toBeFalse();

    BusinessHour::factory()->closed()->create(['weekday' => 1]);
    expect(freshSla()->calendar()->isWorkingMoment(CarbonImmutable::parse('2026-11-09 10:00', 'Asia/Dhaka')))->toBeFalse();
});

it('builds the calendar from business_hours rows when they exist', function () {
    foreach (range(0, 6) as $weekday) {
        BusinessHour::factory()->create(['weekday' => $weekday, 'opens_at' => '08:00:00', 'closes_at' => '12:00:00']);
    }

    $calendar = freshSla()->calendar();

    expect($calendar->isWorkingMoment(CarbonImmutable::parse('2026-11-07 08:30', 'Asia/Dhaka')))->toBeTrue() // Saturday now worked
        ->and($calendar->isWorkingMoment(CarbonImmutable::parse('2026-11-08 13:00', 'Asia/Dhaka')))->toBeFalse();
});
