<?php

use App\Models\User;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Render what a list row shows and return the number of queries it took. */
function renderTicketList(callable $query): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $page = $query();

    foreach ($page as $ticket) {
        $ticket->creator->name;
        $ticket->assignee?->name;
        $ticket->category->name;
    }

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('paginates with list relations without lazy loading', function () {
    $agent = User::factory()->agent()->create();
    Ticket::factory()->count(5)->create();
    Ticket::factory()->count(5)->assigned($agent)->create();

    $page = Ticket::query()->withListRelations()->latest()->paginate(15);

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(10);

    foreach ($page as $ticket) {
        expect($ticket->creator->name)->toBeString()
            ->and($ticket->category->name)->toBeString();
        $ticket->assignee?->name;
    }
});

it('runs the same number of queries for 3 or 30 rows (no N+1)', function () {
    $list = fn () => Ticket::query()->selectListColumns()->withListRelations()->latest()->paginate(50);

    Ticket::factory()->count(3)->assigned()->create();
    $small = renderTicketList($list);

    Ticket::factory()->count(27)->assigned()->create();
    $large = renderTicketList($list);

    // count + page + creator + assignee + category
    expect($small)->toBe(5)->and($large)->toBe($small);
});

it('selects only list columns and loads only id and name of relations', function () {
    Ticket::factory()->assigned()->create();

    $ticket = Ticket::query()->selectListColumns()->withListRelations()->firstOrFail();

    expect(array_keys($ticket->getAttributes()))->toEqualCanonicalizing(Ticket::LIST_COLUMNS)
        ->and(array_keys($ticket->creator->getAttributes()))->toEqualCanonicalizing(['id', 'name'])
        ->and(array_keys($ticket->assignee->getAttributes()))->toEqualCanonicalizing(['id', 'name'])
        ->and(fn () => $ticket->description)->toThrow(MissingAttributeException::class);
});

it('fails fast when a list forgets to eager load', function () {
    Ticket::factory()->count(2)->create();

    $tickets = Ticket::query()->paginate();

    expect(fn () => $tickets->first()->creator)->toThrow(LazyLoadingViolationException::class);
});
