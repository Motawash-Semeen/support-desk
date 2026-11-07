<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\Gate;

function attachmentOn(?ReplyType $replyType): Attachment
{
    $ticket = (new Ticket)->forceFill(['id' => 100, 'status' => TicketStatus::InProgress, 'created_by' => 4, 'assigned_agent_id' => 2]);
    $reply = $replyType === null ? null : (new TicketReply)->forceFill(['id' => 50, 'type' => $replyType]);

    return (new Attachment)
        ->forceFill(['id' => 7, 'ticket_id' => 100, 'ticket_reply_id' => $reply?->id])
        ->setRelation('ticket', $ticket)
        ->setRelation('reply', $reply);
}

it('follows the ticket view rule and hides internal-note files from customers', function (string $role, int $userId, ?ReplyType $on, ?int $expectedStatus) {
    $user = (new User)->forceFill(['id' => $userId, 'role' => Role::from($role)]);

    $response = Gate::forUser($user)->inspect('download', attachmentOn($on));

    if ($expectedStatus === null) {
        expect($response->allowed())->toBeTrue();
    } else {
        expect($response->denied())->toBeTrue()->and($response->status())->toBe($expectedStatus);
    }
})->with([
    'owner, ticket file' => ['customer', 4, null, null],
    'owner, public reply file' => ['customer', 4, ReplyType::Public, null],
    'owner, internal note file' => ['customer', 4, ReplyType::InternalNote, 404],
    'other customer, ticket file' => ['customer', 5, null, 404],
    'other customer, internal note file' => ['customer', 5, ReplyType::InternalNote, 404],
    'assignee, internal note file' => ['agent', 2, ReplyType::InternalNote, null],
    'other agent, internal note file' => ['agent', 3, ReplyType::InternalNote, null],
    'admin, internal note file' => ['admin', 1, ReplyType::InternalNote, null],
]);
