<?php

use App\Models\User;
use App\Modules\Sla\Models\BusinessHour;
use App\Modules\Sla\Models\Holiday;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\TicketService;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessHourSeeder;
use Database\Seeders\SlaPolicySeeder;

beforeEach(function () {
    $this->seed([SlaPolicySeeder::class, BusinessHourSeeder::class]);
    $this->admin = User::factory()->admin()->create();
});

function policiesPayload(array $override = []): array
{
    $payload = [];
    foreach (TicketPriority::cases() as $priority) {
        $payload[$priority->value] = [
            'resolution_minutes' => $priority->defaultSlaMinutes(),
            'warning_threshold' => '0.80',
            'is_active' => '1',
        ];
    }

    return ['policies' => array_replace_recursive($payload, $override)];
}

function hoursPayload(array $override = []): array
{
    $days = [];
    foreach (range(0, 6) as $weekday) {
        $working = $weekday <= 4;
        $days[$weekday] = ['is_working_day' => $working ? '1' : '0', 'opens_at' => '09:00', 'closes_at' => '18:00'];
    }

    return ['days' => array_replace_recursive($days, $override)];
}

it('is admin-only', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->get(route('admin.sla-settings.index'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.sla-settings.policies.update'), policiesPayload())->assertForbidden();
    $this->actingAs($user)->post(route('admin.sla-settings.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])->assertForbidden();
})->with(['agent', 'customer']);

it('shows the current rules', function () {
    $this->actingAs($this->admin)->get(route('admin.sla-settings.index'))
        ->assertOk()
        ->assertSee('policies[critical][resolution_minutes]', false)
        ->assertSee('days[0][opens_at]', false)
        ->assertSee('Sunday');
});

it('saves SLA targets, flushes the cache and applies them to new tickets only', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-08 03:00:00', 'UTC')); // Sunday 09:00 Dhaka
    $customer = User::factory()->create();
    $category = Category::factory()->create();
    $create = fn () => app(TicketService::class)->create($customer, ['subject' => 's', 'description' => 'd', 'priority' => 'critical', 'category_id' => $category->id]);

    $before = $create();
    expect($before->sla_minutes)->toBe(120);

    $this->actingAs($this->admin)
        ->put(route('admin.sla-settings.policies.update'), policiesPayload(['critical' => ['resolution_minutes' => 60, 'warning_threshold' => '0.50']]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $policy = SlaPolicy::query()->forPriority(TicketPriority::Critical)->sole();
    expect($policy->resolution_minutes)->toBe(60)->and($policy->warning_threshold)->toBe(0.5);

    $after = $create();

    expect($after->sla_minutes)->toBe(60)
        ->and($after->due_at->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('10:00')
        ->and($after->sla_warning_at->setTimezone('Asia/Dhaka')->format('H:i'))->toBe('09:30')
        ->and($before->fresh()->sla_minutes)->toBe(120); // snapshot kept
});

it('validates SLA targets', function (array $override, string $field) {
    $this->actingAs($this->admin)
        ->put(route('admin.sla-settings.policies.update'), policiesPayload($override))
        ->assertSessionHasErrors($field);
})->with([
    'zero minutes' => [['high' => ['resolution_minutes' => 0]], 'policies.high.resolution_minutes'],
    'too many minutes' => [['high' => ['resolution_minutes' => 20_161]], 'policies.high.resolution_minutes'],
    'threshold above 0.99' => [['high' => ['warning_threshold' => '1.5']], 'policies.high.warning_threshold'],
    'threshold with three decimals' => [['high' => ['warning_threshold' => '0.805']], 'policies.high.warning_threshold'],
]);

it('rejects unknown priorities', function () {
    $payload = policiesPayload();
    $payload['policies']['urgent'] = ['resolution_minutes' => 10, 'warning_threshold' => '0.5', 'is_active' => '1'];

    $this->actingAs($this->admin)->put(route('admin.sla-settings.policies.update'), $payload)->assertSessionHasErrors('policies');
});

it('saves business hours and uses them for new deadlines', function () {
    // Make Friday a working day 10:00-14:00.
    $this->actingAs($this->admin)
        ->put(route('admin.sla-settings.business-hours.update'), hoursPayload([5 => ['is_working_day' => '1', 'opens_at' => '10:00', 'closes_at' => '14:00']]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $friday = BusinessHour::query()->where('weekday', 5)->sole();
    expect($friday->is_working_day)->toBeTrue()->and($friday->opens_at)->toStartWith('10:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC')); // Thursday 17:00 Dhaka
    $ticket = app(TicketService::class)->create(User::factory()->create(), [
        'subject' => 's', 'description' => 'd', 'priority' => 'critical', 'category_id' => Category::factory()->create()->id,
    ]);

    // 60 minutes Thursday + 60 minutes Friday (opens 10:00) = Friday 11:00.
    expect($ticket->due_at->setTimezone('Asia/Dhaka')->format('D H:i'))->toBe('Fri 11:00');
});

it('validates business hours', function (array $override, string $field) {
    $this->actingAs($this->admin)
        ->put(route('admin.sla-settings.business-hours.update'), hoursPayload($override))
        ->assertSessionHasErrors($field);
})->with([
    'closing before opening' => [[1 => ['opens_at' => '18:00', 'closes_at' => '09:00']], 'days.1.closes_at'],
    'missing opening on a working day' => [[1 => ['opens_at' => '']], 'days.1.opens_at'],
    'bad time format' => [[1 => ['opens_at' => '9am']], 'days.1.opens_at'],
    'no working day at all' => [array_fill(0, 7, ['is_working_day' => '0']), 'days'],
]);

it('adds and removes holidays', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.sla-settings.holidays.store'), ['date' => '2026-12-16', 'name' => 'Victory Day'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $holiday = Holiday::query()->sole();

    $this->actingAs($this->admin)
        ->post(route('admin.sla-settings.holidays.store'), ['date' => '2026-12-16', 'name' => 'Duplicate'])
        ->assertSessionHasErrors('date');

    $this->actingAs($this->admin)->delete(route('admin.sla-settings.holidays.destroy', $holiday))->assertRedirect();

    expect(Holiday::count())->toBe(0);
});

it('counts a new holiday in the next calculation', function () {
    $this->actingAs($this->admin)->post(route('admin.sla-settings.holidays.store'), ['date' => '2026-11-08', 'name' => 'Office closed']);

    $this->travelTo(CarbonImmutable::parse('2026-11-05 11:00:00', 'UTC')); // Thursday 17:00 Dhaka
    $ticket = app(TicketService::class)->create(User::factory()->create(), [
        'subject' => 's', 'description' => 'd', 'priority' => 'critical', 'category_id' => Category::factory()->create()->id,
    ]);

    // Sunday is now a holiday: 60 Thursday + 60 Monday = Monday 10:00.
    expect($ticket->due_at->setTimezone('Asia/Dhaka')->format('D H:i'))->toBe('Mon 10:00')
        ->and(Ticket::count())->toBe(1);
});
