<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Imports\Models\TicketImport;
use App\Modules\Imports\Models\TicketImportError;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketSource;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;

it('loads every ticket relationship', function () {
    $agent = User::factory()->agent()->create();
    $policy = SlaPolicy::factory()->create();
    $ticket = Ticket::factory()->withSlaPolicy($policy)->assigned($agent)->create();

    $public = TicketReply::factory()->for($ticket)->create();
    TicketReply::factory()->for($ticket)->internalNote()->create(['user_id' => $agent->id]);
    Attachment::factory()->for($ticket)->create(['ticket_reply_id' => $public->id]);
    TicketActivity::factory()->for($ticket)->create();

    $loaded = Ticket::query()
        ->with(['creator', 'assignee', 'category', 'slaPolicy', 'replies', 'publicReplies', 'attachments', 'activities'])
        ->findOrFail($ticket->id);

    expect($loaded->creator)->toBeInstanceOf(User::class)
        ->and($loaded->creator->role)->toBe(Role::Customer)
        ->and($loaded->assignee->is($agent))->toBeTrue()
        ->and($loaded->category)->toBeInstanceOf(Category::class)
        ->and($loaded->slaPolicy->is($policy))->toBeTrue()
        ->and($loaded->replies)->toHaveCount(2)
        ->and($loaded->publicReplies->modelKeys())->toBe([$public->id])
        ->and($loaded->attachments)->toHaveCount(1)
        ->and($loaded->activities)->toHaveCount(1);
});

it('loads user relationships', function () {
    $customer = User::factory()->create();
    $agent = User::factory()->agent()->create();
    $categories = Category::factory()->count(2)->create();
    $agent->categories()->attach($categories);

    Ticket::factory()->count(2)->for($customer, 'creator')->create();
    Ticket::factory()->for($customer, 'creator')->assigned($agent)->create();

    $customer->load('createdTickets', 'assignedTickets');
    $agent->load('createdTickets', 'assignedTickets', 'categories');

    expect($customer->createdTickets)->toHaveCount(3)
        ->and($customer->assignedTickets)->toBeEmpty()
        ->and($agent->assignedTickets)->toHaveCount(1)
        ->and($agent->categories->modelKeys())->toEqualCanonicalizing($categories->modelKeys())
        ->and($categories->first()->load('agents')->agents->modelKeys())->toBe([$agent->id]);
});

it('loads reply, attachment, activity and import relationships', function () {
    $reply = TicketReply::factory()->create();
    Attachment::factory()->count(2)->create(['ticket_id' => $reply->ticket_id, 'ticket_reply_id' => $reply->id]);
    $activity = TicketActivity::factory()->create(['ticket_id' => $reply->ticket_id, 'actor_id' => $reply->user_id]);
    $error = TicketImportError::factory()->create();

    $reply->load('ticket', 'author', 'attachments.uploader', 'attachments.reply');
    $activity->load('actor', 'ticket');
    $error->load('import.user', 'import.rowErrors');

    expect($reply->ticket)->toBeInstanceOf(Ticket::class)
        ->and($reply->author)->toBeInstanceOf(User::class)
        ->and($reply->attachments)->toHaveCount(2)
        ->and($reply->attachments->first()->reply->is($reply))->toBeTrue()
        ->and($activity->actor->is($reply->author))->toBeTrue()
        ->and($error->import)->toBeInstanceOf(TicketImport::class)
        ->and($error->import->rowErrors)->toHaveCount(1)
        ->and($error->errors)->toBeArray();
});

it('casts columns to enums, booleans and dates', function () {
    $ticket = Ticket::factory()->breached()->escalated()->create()->fresh();

    expect($ticket->status)->toBeInstanceOf(TicketStatus::class)
        ->and($ticket->priority)->toBeInstanceOf(TicketPriority::class)
        ->and($ticket->source)->toBe(TicketSource::Web)
        ->and($ticket->is_escalated)->toBeTrue()
        ->and($ticket->due_at)->toBeInstanceOf(CarbonInterface::class);
});

it('cascades ticket deletion to replies, attachments and activities', function () {
    $ticket = Ticket::factory()->create();
    $reply = TicketReply::factory()->for($ticket)->create();
    Attachment::factory()->for($ticket)->create(['ticket_reply_id' => $reply->id]);
    TicketActivity::factory()->for($ticket)->create();

    $ticket->delete();

    expect(TicketReply::count())->toBe(0)
        ->and(Attachment::count())->toBe(0)
        ->and(TicketActivity::count())->toBe(0);
});

it('refuses to delete a category or creator that tickets still reference', function () {
    $ticket = Ticket::factory()->create();

    expect(fn () => $ticket->category->delete())->toThrow(QueryException::class)
        ->and(fn () => $ticket->creator->delete())->toThrow(QueryException::class);
});

it('treats ticket activities as append-only', function () {
    $activity = TicketActivity::factory()->create();

    expect(fn () => $activity->update(['description' => 'rewritten']))
        ->toThrow(LogicException::class, 'append-only');
});

it('does not let role be mass assigned', function () {
    expect(fn () => User::query()->create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'secret-password',
        'role' => Role::Admin,
    ]))->toThrow(MassAssignmentException::class);
});

it('defaults new users to customer and available', function () {
    $user = User::query()->create([
        'name' => 'New',
        'email' => 'new@example.com',
        'password' => 'secret-password',
    ]);

    expect($user->role)->toBe(Role::Customer)
        ->and($user->is_available)->toBeTrue()
        ->and($user->fresh()->role)->toBe(Role::Customer);
});
