<?php

use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\Route;

it('rejects update, save and delete on an activity', function () {
    $activity = TicketActivity::factory()->create();

    expect(fn () => $activity->update(['description' => 'rewritten']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $activity->forceFill(['description' => 'rewritten'])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $activity->delete())->toThrow(LogicException::class, 'append-only');

    expect($activity->fresh()->description)->toBe('Ticket created');
});

it('has no updated_at column or timestamp', function () {
    $activity = TicketActivity::factory()->create();

    expect(TicketActivity::UPDATED_AT)->toBeNull()
        ->and(array_key_exists('updated_at', $activity->getAttributes()))->toBeFalse();
});

it('exposes no route that could change or delete activities', function () {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'activit'))
        ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []);

    expect($writes)->toBeEmpty();
});

it('still disappears with its ticket through the database cascade', function () {
    $activity = TicketActivity::factory()->create();

    Ticket::query()->whereKey($activity->ticket_id)->delete();

    expect(TicketActivity::query()->find($activity->id))->toBeNull();
});
