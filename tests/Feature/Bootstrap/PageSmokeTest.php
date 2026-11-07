<?php

use App\Models\User;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Imports\Models\TicketImport;
use App\Modules\Notifications\Notifications\TicketResolvedNotification;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\Route;

/*
 * "Click through every page" with strict Eloquent on (Model::preventLazyLoading
 * throws outside production): any N+1 on a page turns into a 500 here.
 * Every parameterless GET page is requested as each role, plus the pages
 * that take a ticket or import, on a populated data set.
 */

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create();
    $this->customer = User::factory()->customer()->create();
    $category = Category::factory()->create();
    $this->agent->categories()->attach($category);

    $this->tickets = collect([
        Ticket::factory()->for($this->customer, 'creator')->for($category)->create(),
        Ticket::factory()->for($this->customer, 'creator')->inProgress()->assigned($this->agent)->breached()->escalated()->create(),
        Ticket::factory()->for($this->customer, 'creator')->resolved()->create(),
        Ticket::factory()->for($this->customer, 'creator')->closed()->approaching()->create(),
    ]);

    foreach ($this->tickets as $ticket) {
        $public = TicketReply::factory()->for($ticket)->create(['user_id' => $this->customer->id]);
        TicketReply::factory()->for($ticket)->internalNote()->create(['user_id' => $this->agent->id]);
        Attachment::factory()->for($ticket)->create(['ticket_reply_id' => $public->id, 'uploaded_by' => $this->customer->id]);
        TicketActivity::factory()->for($ticket)->count(3)->create();
    }

    $this->customer->notify(new TicketResolvedNotification($this->tickets[2]));
    $this->agent->notify(new TicketResolvedNotification($this->tickets[1]));
    $this->import = TicketImport::factory()->completed(5, 1)->create(['user_id' => $this->admin->id]);
});

test('every parameterless page renders for every role without lazy loading or server errors', function (string $role) {
    $user = $this->{$role};
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{') || ! in_array('auth', $route->gatherMiddleware(), true)) {
            continue;
        }
        if (in_array($route->getName(), ['admin.imports.sample', 'staff.customers.search'], true)) {
            continue; // a download and a JSON endpoint, covered by their own tests
        }

        $status = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'))->getStatusCode();

        expect($status)->toBeLessThan(500, "GET /{$route->uri()} as {$role} returned {$status}");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(8);
})->with(['admin', 'agent', 'customer']);

test('every ticket page renders for staff and its owner, with replies, notes, files and timeline', function () {
    foreach ($this->tickets as $ticket) {
        $this->actingAs($this->admin)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($this->agent)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($this->customer)->get(route('tickets.show', $ticket))->assertOk();
        $this->actingAs($this->admin)->get(route('tickets.edit', $ticket))->assertOk();
        $this->actingAs($this->admin)->get(route('tickets.show', [$ticket, 'timeline' => 'oldest']))->assertOk();
    }

    $this->actingAs($this->admin)->get(route('admin.imports.show', $this->import))->assertOk();
});
