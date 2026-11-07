<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Sla\Models\BusinessHour;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketSequence;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\UserSeeder;

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

it('seeds the team, customers and realistic sample tickets through the real actions', function () {
    $this->seed(DatabaseSeeder::class);

    $samples = require database_path('seeders/data/tickets.php');
    $flows = collect($samples)->countBy('flow');

    expect(User::query()->role(Role::Admin)->count())->toBe(1)
        ->and(User::query()->agents()->count())->toBe(count(UserSeeder::AGENTS))
        ->and(User::query()->role(Role::Customer)->count())->toBe(count(UserSeeder::CUSTOMERS))
        ->and(User::query()->agents()->has('categories')->count())->toBe(count(UserSeeder::AGENTS))
        ->and(Ticket::count())->toBe(count($samples))
        // Each ticket ended where its flow says.
        ->and(Ticket::query()->where('status', TicketStatus::Closed)->count())->toBe($flows['closed'])
        ->and(Ticket::query()->where('status', TicketStatus::Resolved)->count())->toBe($flows['resolved'] + $flows['late'])
        ->and(Ticket::query()->where('status', TicketStatus::WaitingForCustomer)->count())->toBe($flows['waiting'])
        ->and(Ticket::query()->unassigned()->count())->toBe($flows['new'] + $flows['stale'])
        ->and(Ticket::query()->slaBreached()->count())->toBe($flows['breached'] + $flows['stale'])
        ->and(Ticket::query()->slaApproaching()->count())->toBe($flows['approaching'])
        ->and(Ticket::query()->where('is_escalated', true)->count())->toBeGreaterThanOrEqual($flows['breached'] + $flows['stale'])
        ->and(Ticket::query()->whereNull('sla_policy_id')->count())->toBe(0)
        // Every ticket has a history and every step is in the past.
        ->and(Ticket::query()->doesntHave('activities')->count())->toBe(0)
        ->and(TicketActivity::query()->where('created_at', '>', now())->count())->toBe(0)
        ->and(TicketReply::query()->count())->toBeGreaterThan(count($samples))
        // The counter must be at the highest seeded number so new tickets never collide.
        ->and(TicketSequence::query()->find(now()->year)?->last_number)->toBe(
            Ticket::query()->pluck('ticket_number')->map(fn (string $n) => (int) substr($n, strrpos($n, '-') + 1))->max()
        );
});
