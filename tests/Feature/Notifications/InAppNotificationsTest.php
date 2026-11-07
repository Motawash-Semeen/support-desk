<?php

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketResolvedNotification;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->customer = User::factory()->create();
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->create();

    // Queue is sync in tests, so the database channel writes immediately.
    $this->customer->notify(new TicketResolvedNotification($this->ticket));
    $this->customer->notify(new TicketResolvedNotification($this->ticket));
    $this->notification = $this->customer->notifications()->firstOrFail();
});

it('shows the unread count on the bell with one query per request', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($this->customer)->get(route('tickets.index'))
        ->assertOk()
        ->assertSee('data-testid="unread-count"', false)
        ->assertSee('2 unread notifications');

    $countQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "notifications"') || str_contains($q['query'], 'from `notifications`'));
    DB::disableQueryLog();

    expect($countQueries)->toHaveCount(1);
});

it('lists notifications and marks one as read', function () {
    $this->actingAs($this->customer)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('was marked resolved');

    $this->actingAs($this->customer)
        ->from(route('notifications.index'))
        ->post(route('notifications.read', $this->notification->id))
        ->assertRedirect(route('notifications.index'));

    expect($this->notification->fresh()->read_at)->not->toBeNull()
        ->and($this->customer->unreadNotifications()->count())->toBe(1);
});

it('opens the ticket from a notification', function () {
    $this->actingAs($this->customer)
        ->post(route('notifications.read', $this->notification->id), ['open' => 1])
        ->assertRedirect(route('tickets.show', $this->ticket->ticket_number));
});

it('cannot read or mark someone else\'s notification', function () {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->post(route('notifications.read', $this->notification->id))->assertNotFound();
    $this->actingAs($intruder)->get(route('notifications.index'))->assertOk()->assertDontSee('was marked resolved');

    expect($this->notification->fresh()->read_at)->toBeNull();
});

it('marks all as read for the current user only', function () {
    $other = User::factory()->create();
    $other->notify(new TicketResolvedNotification($this->ticket));

    $this->actingAs($this->customer)->post(route('notifications.read-all'))->assertRedirect();

    expect($this->customer->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(1);
});

it('requires authentication', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});
