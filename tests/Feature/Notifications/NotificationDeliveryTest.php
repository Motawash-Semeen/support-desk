<?php

use App\Models\User;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Notifications\TicketNotification;
use App\Modules\Notifications\TicketNotifier;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->customer = User::factory()->create(['name' => 'Cara']);
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->create(['subject' => 'Printer jam']);
});

it('queues mail on the notifications queue and database on default', function () {
    Queue::fake();

    $this->customer->notify(resolvedNotification($this->ticket));

    Queue::assertPushedOn('notifications', SendQueuedNotifications::class, fn ($job) => $job->channels === ['mail']);
    Queue::assertPushedOn('default', SendQueuedNotifications::class, fn ($job) => $job->channels === ['database']);
});

it('retries with backoff and uses mail + database', function () {
    $notification = resolvedNotification($this->ticket);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via($this->customer))->toBe(['mail', 'database'])
        ->and($notification->tries)->toBe(3)
        ->and($notification->backoff)->toBe([30, 120, 600]);
});

it('stores ticket id, number, kind, message and url for the in-app list', function () {
    $data = (resolvedNotification($this->ticket))->toArray($this->customer);

    expect($data)->toBe([
        'ticket_id' => $this->ticket->id,
        'ticket_number' => $this->ticket->ticket_number,
        'kind' => 'ticket_resolved',
        'message' => "Your ticket {$this->ticket->ticket_number} was marked resolved. Reply or reopen it if the problem is not fixed.",
        'url' => route('tickets.show', $this->ticket),
    ]);
});

it('renders the markdown email with the ticket and a link', function () {
    $html = (string) (resolvedNotification($this->ticket))->toMail($this->customer)->render();

    expect($html)->toContain('Hello Cara')
        ->and($html)->toContain($this->ticket->ticket_number)
        ->and($html)->toContain('Printer jam')
        ->and($html)->toContain(route('tickets.show', $this->ticket));
});

it('logs ticket and recipient when delivery finally fails', function () {
    Log::spy();
    $notification = resolvedNotification($this->ticket);
    $notification->via($this->customer); // as the sender does before queuing

    $notification->failed(new RuntimeException('SMTP down'));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Ticket notification failed.'
        && $context['ticket_id'] === $this->ticket->id
        && $context['notifiable_id'] === $this->customer->id
        && $context['error'] === 'SMTP down');
});

it('never sends an internal note to the customer', function () {
    Notification::fake();
    $agent = User::factory()->agent()->create();
    $note = TicketReply::factory()->for($this->ticket)->internalNote()->create();

    app(TicketNotifier::class)->replyAdded($note, $this->ticket, $agent);

    Notification::assertNothingSentTo($this->customer);
});

it('does not fail the request when mail delivery would fail later', function () {
    // Delivery happens on the queue: the request only pushes jobs.
    Queue::fake();
    $agent = User::factory()->agent()->create();
    $ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->assigned($agent)->create();

    $this->actingAs($agent)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'On it'])
        ->assertRedirect();

    Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->notification instanceof TicketNotification);
});
