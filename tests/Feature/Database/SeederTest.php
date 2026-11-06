<?php

use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Models\BusinessHour;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketSequence;
use App\Models\User;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SlaPolicySeeder;

it('seeds SLA policies from config/support.php', function () {
    config(['support.sla.high' => 300, 'support.sla.warning_threshold' => 0.75]);

    $this->seed(SlaPolicySeeder::class);

    $high = SlaPolicy::query()->forPriority(TicketPriority::High)->sole();

    expect(SlaPolicy::count())->toBe(4)
        ->and($high->resolution_minutes)->toBe(300)
        ->and($high->warning_threshold)->toBe(0.75);
});

it('seeds Sunday to Thursday 09:00-18:00 business hours', function () {
    $this->seed(BusinessHourSeeder::class);

    $hours = BusinessHour::query()->orderBy('weekday')->get();

    expect($hours)->toHaveCount(7)
        ->and($hours->where('is_working_day', true)->pluck('weekday')->all())->toBe([0, 1, 2, 3, 4])
        ->and(substr($hours[0]->opens_at, 0, 5))->toBe('09:00')
        ->and(substr($hours[0]->closes_at, 0, 5))->toBe('18:00')
        ->and($hours[5]->opens_at)->toBeNull();
});

it('is idempotent for reference data', function () {
    $this->seed([CategorySeeder::class, SlaPolicySeeder::class, BusinessHourSeeder::class]);
    $this->seed([CategorySeeder::class, SlaPolicySeeder::class, BusinessHourSeeder::class]);

    expect(Category::count())->toBe(count(CategorySeeder::CATEGORIES))
        ->and(SlaPolicy::count())->toBe(4)
        ->and(BusinessHour::count())->toBe(7);
});

it('seeds demo users and about 200 consistent demo tickets', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->role(Role::Admin)->count())->toBe(1)
        ->and(User::query()->agents()->count())->toBe(3)
        ->and(User::query()->role(Role::Customer)->count())->toBe(5)
        ->and(User::query()->agents()->has('categories')->count())->toBe(3)
        ->and(Ticket::count())->toBe(200)
        ->and(Ticket::query()->slaBreached()->count())->toBeGreaterThan(0)
        ->and(Ticket::query()->slaApproaching()->count())->toBeGreaterThan(0)
        ->and(Ticket::query()->unassigned()->count())->toBeGreaterThan(0)
        ->and(Ticket::query()->whereNull('sla_policy_id')->count())->toBe(0)
        // The counter must be at the highest seeded number so new tickets never collide.
        ->and(TicketSequence::query()->find(now()->year)?->last_number)->toBe(
            Ticket::query()->pluck('ticket_number')->map(fn (string $n) => (int) substr($n, strrpos($n, '-') + 1))->max()
        );
});
