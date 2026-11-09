<?php

use App\Models\User;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Notifications\Notifications\TicketResolvedNotification;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\Storage;

/*
 * Insecure direct object references: a signed-in customer who knows or
 * guesses another customer's ticket number, attachment id or notification id.
 * Every attempt must answer exactly like a resource that does not exist (404),
 * leak nothing, and change nothing.
 */

beforeEach(function () {
    $this->attacker = User::factory()->customer()->create();
    $this->victim = User::factory()->customer()->create();
    $this->ticket = Ticket::factory()->for($this->victim, 'creator')->resolved()->create(['subject' => 'Victim secret subject']);

    $reply = TicketReply::factory()->for($this->ticket)->create(['user_id' => $this->victim->id]);
    $this->attachment = Attachment::factory()->for($this->ticket)->create(['ticket_reply_id' => $reply->id, 'uploaded_by' => $this->victim->id]);
    Storage::disk('private')->put($this->attachment->stored_name, 'victim file');

    $this->snapshot = fn () => [
        $this->ticket->fresh()->only(['status', 'priority', 'subject', 'assigned_agent_id', 'resolved_at', 'closed_at', 'updated_at']),
        $this->ticket->replies()->count(),
        $this->ticket->activities()->count(),
    ];
    $this->before = ($this->snapshot)();
});

test('a customer gets 404 on every ticket route for another customer\'s ticket and nothing changes', function (string $method, string $route, array $payload) {
    $response = $this->actingAs($this->attacker)->{$method}(route($route, $this->ticket), $payload);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('Victim secret subject')
        ->and(($this->snapshot)())->toEqual($this->before);
})->with([
    'view' => ['get', 'tickets.show', []],
    'edit form' => ['get', 'tickets.edit', []],
    'update' => ['patch', 'tickets.update', ['subject' => 'pwned', 'description' => 'pwned', 'priority' => 'critical']],
    'reply' => ['post', 'tickets.replies.store', ['body' => 'pwned']],
    'resolve' => ['post', 'tickets.status', ['status' => 'resolved']],
    'close' => ['post', 'tickets.status', ['status' => 'closed']],
    'reopen' => ['post', 'tickets.status', ['status' => 'in_progress']],
    'change status' => ['post', 'tickets.status', ['status' => TicketStatus::Open->value]],
    'accept' => ['post', 'tickets.accept', []],
    'assign' => ['post', 'tickets.assign', ['agent_id' => 1]],
    'release' => ['post', 'tickets.release', []],
]);

test('a customer gets the same 404 page for another customer\'s ticket as for a missing one', function () {
    $this->actingAs($this->attacker)->get(route('tickets.show', $this->ticket))->assertNotFound()->assertSee('Page not found');
    $this->actingAs($this->attacker)->get(route('tickets.show', 'TKT-2026-999998'))->assertNotFound()->assertSee('Page not found');
});

test('a customer cannot enumerate neighbouring ticket numbers', function () {
    $own = Ticket::factory()->for($this->attacker, 'creator')->create();
    [$prefix, $year, $seq] = explode('-', $own->ticket_number);

    foreach ([-2, -1, 1, 2] as $step) {
        $guess = sprintf('%s-%s-%0'.strlen($seq).'d', $prefix, $year, (int) $seq + $step);
        $this->actingAs($this->attacker)->get(route('tickets.show', $guess))->assertNotFound();
    }

    $this->actingAs($this->attacker)->get(route('tickets.show', $this->ticket->ticket_number))->assertNotFound();
});

test('a customer cannot download another customer\'s attachment by id', function () {
    $this->actingAs($this->attacker)->get(route('attachments.download', $this->attachment))->assertNotFound();
    $this->actingAs($this->victim)->get(route('attachments.download', $this->attachment))->assertOk();
});

test('a customer cannot mark or open another user\'s notification by id', function () {
    $this->victim->notify(new TicketResolvedNotification($this->ticket));
    $notification = $this->victim->notifications()->sole();

    $this->actingAs($this->attacker)->post(route('notifications.read', $notification->id), ['open' => 1])->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

test('a customer cannot widen the ticket list with staff-only filters', function () {
    Ticket::factory()->for($this->attacker, 'creator')->create(['subject' => 'Attacker own ticket']);

    $this->actingAs($this->attacker)
        ->get(route('tickets.index', ['customer' => $this->victim->id, 'status' => ['resolved'], 'q' => 'Victim']))
        ->assertOk()
        ->assertDontSee('Victim secret subject');

    $this->actingAs($this->attacker)
        ->get(route('tickets.index', ['status' => ['open', 'resolved']]))
        ->assertSee('Attacker own ticket')
        ->assertDontSee('Victim secret subject');
});

test('a customer cannot reach staff or admin pages', function (string $route) {
    $this->actingAs($this->attacker)->get(route($route))->assertForbidden();
})->with(['staff.dashboard', 'staff.unassigned', 'staff.customers.search', 'admin.sla-settings.index', 'admin.imports.index']);
