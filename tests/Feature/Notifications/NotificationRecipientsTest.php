<?php

use App\Models\User;
use App\Modules\Assignment\AssignmentService;
use App\Modules\Conversation\ConversationService;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Notifications\TicketNotification;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\TicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['support.assignment.auto_on_create' => false]);

    $this->billing = Category::factory()->create(['name' => 'Billing']);
    $this->customer = User::factory()->create();
    $this->billingAgent = User::factory()->agent()->create();
    $this->billingAgent->categories()->attach($this->billing);
    $this->otherAgent = User::factory()->agent()->create();
    $this->admin = User::factory()->admin()->create();
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->for($this->billing)->create();
});

it('confirms a new ticket to the customer and alerts admins and category agents', function () {
    $ticket = app(TicketService::class)->create($this->customer, [
        'subject' => 'Refund', 'description' => 'Double charged', 'priority' => 'high', 'category_id' => $this->billing->id,
    ]);

    Notification::assertSentTo($this->customer, TicketNotification::class, fn ($n) => $n->kind === 'ticket_received' && $n->ticket->is($ticket));
    Notification::assertSentTo([$this->admin, $this->billingAgent], TicketNotification::class, ofKind('ticket_created'));
    Notification::assertNotSentTo($this->otherAgent, TicketNotification::class, ofKind('ticket_received', 'ticket_created'));
});

it('does not alert the staff member who created the ticket', function () {
    app(TicketService::class)->create($this->admin, [
        'subject' => 'Internal', 'description' => 'x', 'priority' => 'low', 'category_id' => $this->billing->id,
    ]);

    Notification::assertNotSentTo($this->admin, TicketNotification::class, ofKind('ticket_received', 'ticket_created'));
    Notification::assertSentTo($this->billingAgent, TicketNotification::class, ofKind('ticket_received', 'ticket_created'));
});

it('tells the agent and the customer about an admin assignment', function () {
    app(AssignmentService::class)->assign($this->admin, $this->ticket, $this->billingAgent, null);

    Notification::assertSentTo($this->billingAgent, TicketNotification::class, ofKind('assigned_to_you'));
    Notification::assertSentTo($this->customer, TicketNotification::class, ofKind('ticket_assigned'));
    Notification::assertNotSentTo($this->admin, TicketNotification::class, ofKind('assigned_to_you', 'ticket_assigned'));
});

it('does not notify an agent who accepted the ticket themselves', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->billingAgent, $this->billingAgent);

    Notification::assertNotSentTo($this->billingAgent, TicketNotification::class, ofKind('assigned_to_you', 'ticket_assigned'));
    Notification::assertSentTo($this->customer, TicketNotification::class, ofKind('assigned_to_you', 'ticket_assigned'));
});

it('notifies the agent and the customer about an auto-assignment', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->billingAgent, null);

    Notification::assertSentTo($this->billingAgent, TicketNotification::class, ofKind('assigned_to_you'));
    Notification::assertSentTo($this->customer, TicketNotification::class, ofKind('ticket_assigned'));
    Notification::assertNotSentTo($this->admin, TicketNotification::class, ofKind('assigned_to_you', 'ticket_assigned'));
});

it('sends an agent reply to the customer only', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->billingAgent, $this->billingAgent);

    app(ConversationService::class)->addReply($this->billingAgent, $this->ticket, 'Refund issued', ReplyType::Public);

    Notification::assertSentTo($this->customer, TicketNotification::class, ofKind('agent_replied'));
    Notification::assertNotSentTo([$this->billingAgent, $this->admin], TicketNotification::class, ofKind('agent_replied'));
});

it('never notifies the customer about an internal note', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->billingAgent, $this->billingAgent);
    $before = Notification::sent($this->customer, TicketNotification::class, ofKind('assigned_to_you', 'ticket_assigned'))->count();

    app(ConversationService::class)->addReply($this->admin, $this->ticket, 'Check the ledger', ReplyType::InternalNote);

    Notification::assertNotSentTo($this->customer, TicketNotification::class, ofKind('internal_note_added'));
    Notification::assertNotSentTo($this->customer, TicketNotification::class, ofKind('agent_replied'));
    expect(Notification::sent($this->customer, TicketNotification::class, ofKind('assigned_to_you', 'ticket_assigned'))->count())->toBe($before);

    // The assignee hears about a note someone else wrote; the author does not.
    Notification::assertSentTo($this->billingAgent, TicketNotification::class, ofKind('internal_note_added'));
    Notification::assertNotSentTo($this->admin, TicketNotification::class, ofKind('internal_note_added'));
});

it('sends nothing for a note written by the assignee', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->billingAgent, $this->billingAgent);

    app(ConversationService::class)->addReply($this->billingAgent, $this->ticket, 'Self reminder', ReplyType::InternalNote);

    Notification::assertNothingSentTo($this->billingAgent);
    Notification::assertNotSentTo($this->customer, TicketNotification::class, ofKind('internal_note_added'));
});

it('routes a customer reply to the assignee, or to admins while unassigned', function () {
    app(ConversationService::class)->addReply($this->customer, $this->ticket, 'Any news?', ReplyType::Public);
    Notification::assertSentTo($this->admin, TicketNotification::class, ofKind('customer_replied'));
    Notification::assertNotSentTo($this->customer, TicketNotification::class, ofKind('customer_replied'));

    app(AssignmentService::class)->claim($this->ticket, $this->billingAgent, $this->billingAgent);
    $adminCount = Notification::sent($this->admin, TicketNotification::class, ofKind('customer_replied'))->count();

    app(ConversationService::class)->addReply($this->customer, $this->ticket, 'Still waiting', ReplyType::Public);
    Notification::assertSentTo($this->billingAgent, TicketNotification::class, ofKind('customer_replied'));
    expect(Notification::sent($this->admin, TicketNotification::class, ofKind('customer_replied'))->count())->toBe($adminCount);
});

it('tells the customer when their ticket is resolved', function () {
    app(AssignmentService::class)->claim($this->ticket, $this->billingAgent, $this->billingAgent);

    app(TicketService::class)->changeStatus($this->billingAgent, $this->ticket, TicketStatus::Resolved);

    Notification::assertSentTo($this->customer, TicketNotification::class, ofKind('ticket_resolved'));
    Notification::assertNotSentTo($this->billingAgent, TicketNotification::class, ofKind('ticket_resolved'));
});

it('sends nothing when the change rolls back', function () {
    try {
        DB::transaction(function () {
            app(ConversationService::class)->addReply($this->customer, $this->ticket, 'Hello', ReplyType::Public);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
});
