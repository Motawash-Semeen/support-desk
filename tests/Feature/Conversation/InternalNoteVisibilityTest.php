<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Assignment\Actions\ClaimTicket;
use App\Modules\Conversation\Actions\AddReply;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Notifications\Notifications\AgentRepliedNotification;
use App\Modules\Notifications\Notifications\InternalNoteAddedNotification;
use App\Modules\Notifications\Notifications\TicketAssignedNotification;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Internal notes must never reach a customer: not in the page, not in JSON,
| not in the activity timeline, not in notifications or mail. A unique marker
| is written into a note (body and attachment name) and searched for in
| everything the customer can receive.
*/

const MARKER = 'INTERNAL-ONLY-7f3a9c';

beforeEach(function () {
    Storage::fake('private');
    Notification::fake();
    Mail::fake();

    $this->customer = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->create();

    app(ClaimTicket::class)($this->ticket, $this->agent, $this->agent);
    app(AddReply::class)($this->customer, $this->ticket, 'My printer is broken', ReplyType::Public);
    app(AddReply::class)($this->agent, $this->ticket, 'Customer-facing answer', ReplyType::Public);
    app(AddReply::class)($this->agent, $this->ticket, 'Note: '.MARKER.' do not tell the customer', ReplyType::InternalNote, [
        UploadedFile::fake()->create(MARKER.'-logs.txt', 1, 'text/plain'),
    ]);
});

it('never shows the internal note in the customer page', function () {
    $this->actingAs($this->customer)
        ->get(route('tickets.show', $this->ticket))
        ->assertOk()
        ->assertSee('Customer-facing answer')
        ->assertDontSee(MARKER)
        ->assertDontSee('Internal note');
});

it('never includes the internal note in the customer JSON', function () {
    $response = $this->actingAs($this->customer)->getJson(route('tickets.show', $this->ticket))->assertOk();

    expect($response->getContent())->not->toContain(MARKER)
        ->and(collect($response->json('data.replies'))->pluck('type')->unique()->all())->toBe(['public']);
});

it('hides internal activity from the customer timeline but keeps the public story', function () {
    $response = $this->actingAs($this->customer)->getJson(route('tickets.show', $this->ticket));
    $types = collect($response->json('data.activity'))->pluck('type');

    expect($types)->toContain(ActivityType::CustomerReplied->value, ActivityType::AgentReplied->value)
        ->and($types)->not->toContain(ActivityType::InternalNoteAdded->value)
        ->and($types)->not->toContain(ActivityType::Assigned->value)
        ->and($response->getContent())->not->toContain(MARKER);
});

it('never sends the internal note to the customer by notification or mail', function () {
    // The customer legitimately hears about the assignment and the public reply ...
    Notification::assertSentTo($this->customer, TicketAssignedNotification::class);
    Notification::assertSentTo($this->customer, AgentRepliedNotification::class);
    Notification::assertNotSentTo($this->customer, InternalNoteAddedNotification::class);

    // ... but nothing they receive (rendered email or in-app payload) contains the note.
    $sent = Notification::sent($this->customer, TicketAssignedNotification::class)
        ->merge(Notification::sent($this->customer, AgentRepliedNotification::class));

    foreach ($sent as $notification) {
        $mail = (string) $notification->toMail($this->customer)->render();

        expect($mail)->not->toContain(MARKER)
            ->and(json_encode($notification->toArray($this->customer)))->not->toContain(MARKER);
    }

    Mail::assertNothingOutgoing(); // no direct mailables either
});

it('shows the note and its activity to staff', function () {
    $this->actingAs($this->agent)
        ->get(route('tickets.show', $this->ticket))
        ->assertSee(MARKER)
        ->assertSee('Internal note added');

    $json = $this->actingAs($this->agent)->getJson(route('tickets.show', $this->ticket));
    expect($json->getContent())->toContain(MARKER);
});

it('never exposes storage paths in the JSON', function () {
    $json = $this->actingAs($this->agent)->getJson(route('tickets.show', $this->ticket))->getContent();

    expect($json)->not->toContain('tickets/'.$this->ticket->id.'/')
        ->and($json)->toContain('/download');
});
