<?php

use App\Models\User;
use App\Modules\Assignment\Actions\AssignTicket;
use App\Modules\Assignment\Actions\ClaimTicket;
use App\Modules\Conversation\Actions\AddReply;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Notifications\Notifications\AgentRepliedNotification;
use App\Modules\Notifications\Notifications\CustomerRepliedNotification;
use App\Modules\Notifications\Notifications\InternalNoteAddedNotification;
use App\Modules\Notifications\Notifications\TicketAssignedNotification;
use App\Modules\Notifications\Notifications\TicketCreatedNotification;
use App\Modules\Notifications\Notifications\TicketResolvedNotification;
use App\Modules\Tickets\Actions\ChangeTicketStatus;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
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
    $ticket = app(CreateTicket::class)($this->customer, [
        'subject' => 'Refund', 'description' => 'Double charged', 'priority' => 'high', 'category_id' => $this->billing->id,
    ]);

    Notification::assertSentTo($this->customer, TicketCreatedNotification::class, fn ($n) => $n->forCustomer && $n->ticket->is($ticket));
    Notification::assertSentTo([$this->admin, $this->billingAgent], TicketCreatedNotification::class, fn ($n) => ! $n->forCustomer);
    Notification::assertNotSentTo($this->otherAgent, TicketCreatedNotification::class);
});

it('does not alert the staff member who created the ticket', function () {
    app(CreateTicket::class)($this->admin, [
        'subject' => 'Internal', 'description' => 'x', 'priority' => 'low', 'category_id' => $this->billing->id,
    ]);

    Notification::assertNotSentTo($this->admin, TicketCreatedNotification::class);
    Notification::assertSentTo($this->billingAgent, TicketCreatedNotification::class);
});

it('tells the agent and the customer about an admin assignment', function () {
    app(AssignTicket::class)($this->admin, $this->ticket, $this->billingAgent, null);

    Notification::assertSentTo($this->billingAgent, TicketAssignedNotification::class, fn ($n) => $n->forAgent);
    Notification::assertSentTo($this->customer, TicketAssignedNotification::class, fn ($n) => ! $n->forAgent);
    Notification::assertNotSentTo($this->admin, TicketAssignedNotification::class);
});

it('does not notify an agent who accepted the ticket themselves', function () {
    app(ClaimTicket::class)($this->ticket, $this->billingAgent, $this->billingAgent);

    Notification::assertNotSentTo($this->billingAgent, TicketAssignedNotification::class);
    Notification::assertSentTo($this->customer, TicketAssignedNotification::class);
});

it('notifies the agent and the customer about an auto-assignment', function () {
    app(ClaimTicket::class)($this->ticket, $this->billingAgent, null);

    Notification::assertSentTo($this->billingAgent, TicketAssignedNotification::class, fn ($n) => $n->forAgent);
    Notification::assertSentTo($this->customer, TicketAssignedNotification::class, fn ($n) => ! $n->forAgent);
    Notification::assertNotSentTo($this->admin, TicketAssignedNotification::class);
});

it('sends an agent reply to the customer only', function () {
    app(ClaimTicket::class)($this->ticket, $this->billingAgent, $this->billingAgent);

    app(AddReply::class)($this->billingAgent, $this->ticket, 'Refund issued', ReplyType::Public);

    Notification::assertSentTo($this->customer, AgentRepliedNotification::class);
    Notification::assertNotSentTo([$this->billingAgent, $this->admin], AgentRepliedNotification::class);
});

it('never notifies the customer about an internal note', function () {
    app(ClaimTicket::class)($this->ticket, $this->billingAgent, $this->billingAgent);
    $before = Notification::sent($this->customer, TicketAssignedNotification::class)->count();

    app(AddReply::class)($this->admin, $this->ticket, 'Check the ledger', ReplyType::InternalNote);

    Notification::assertNotSentTo($this->customer, InternalNoteAddedNotification::class);
    Notification::assertNotSentTo($this->customer, AgentRepliedNotification::class);
    expect(Notification::sent($this->customer, TicketAssignedNotification::class)->count())->toBe($before);

    // The assignee hears about a note someone else wrote; the author does not.
    Notification::assertSentTo($this->billingAgent, InternalNoteAddedNotification::class);
    Notification::assertNotSentTo($this->admin, InternalNoteAddedNotification::class);
});

it('sends nothing for a note written by the assignee', function () {
    app(ClaimTicket::class)($this->ticket, $this->billingAgent, $this->billingAgent);

    app(AddReply::class)($this->billingAgent, $this->ticket, 'Self reminder', ReplyType::InternalNote);

    Notification::assertNothingSentTo($this->billingAgent);
    Notification::assertNotSentTo($this->customer, InternalNoteAddedNotification::class);
});

it('routes a customer reply to the assignee, or to admins while unassigned', function () {
    app(AddReply::class)($this->customer, $this->ticket, 'Any news?', ReplyType::Public);
    Notification::assertSentTo($this->admin, CustomerRepliedNotification::class);
    Notification::assertNotSentTo($this->customer, CustomerRepliedNotification::class);

    app(ClaimTicket::class)($this->ticket, $this->billingAgent, $this->billingAgent);
    $adminCount = Notification::sent($this->admin, CustomerRepliedNotification::class)->count();

    app(AddReply::class)($this->customer, $this->ticket, 'Still waiting', ReplyType::Public);
    Notification::assertSentTo($this->billingAgent, CustomerRepliedNotification::class);
    expect(Notification::sent($this->admin, CustomerRepliedNotification::class)->count())->toBe($adminCount);
});

it('tells the customer when their ticket is resolved', function () {
    app(ClaimTicket::class)($this->ticket, $this->billingAgent, $this->billingAgent);

    app(ChangeTicketStatus::class)($this->billingAgent, $this->ticket, TicketStatus::Resolved);

    Notification::assertSentTo($this->customer, TicketResolvedNotification::class);
    Notification::assertNotSentTo($this->billingAgent, TicketResolvedNotification::class);
});

it('sends nothing when the change rolls back', function () {
    try {
        DB::transaction(function () {
            app(AddReply::class)($this->customer, $this->ticket, 'Hello', ReplyType::Public);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
});
