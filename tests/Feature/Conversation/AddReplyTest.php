<?php

use App\Actions\Replies\AddReply;
use App\Enums\ActivityType;
use App\Enums\ReplyType;
use App\Enums\TicketStatus;
use App\Events\ReplyAdded;
use App\Exceptions\ReplyNotAllowed;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\TicketActivityRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    $this->customer = User::factory()->create();
    $this->agent = User::factory()->agent()->create(['name' => 'Sam Agent']);
    $this->reply = fn (User $actor, Ticket $ticket, ReplyType $type = ReplyType::Public, array $files = [], string $body = 'Hello') => app(AddReply::class)($actor, $ticket, $body, $type, $files);
});

it('sets first_response_at on the first public staff reply only', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->assigned($this->agent)->create();

    ($this->reply)($this->customer, $ticket);
    expect($ticket->fresh()->first_response_at)->toBeNull();

    ($this->reply)($this->agent, $ticket, ReplyType::InternalNote);
    expect($ticket->fresh()->first_response_at)->toBeNull();

    $this->travel(5)->minutes();
    ($this->reply)($this->agent, $ticket);
    $first = $ticket->fresh()->first_response_at;
    expect($first)->not->toBeNull();

    $this->travel(5)->minutes();
    ($this->reply)($this->agent, $ticket);
    expect($ticket->fresh()->first_response_at->equalTo($first))->toBeTrue();
});

it('moves a waiting ticket back to in progress when the customer replies', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->waitingForCustomer()->assigned($this->agent)->create();

    ($this->reply)($this->customer, $ticket);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($ticket->activities()->where('type', ActivityType::StatusChanged)->sole()->new_values['reason'])->toBe('Customer replied');
});

it('moves an unassigned waiting ticket back to open when the customer replies', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->create(['status' => TicketStatus::WaitingForCustomer]);

    ($this->reply)($this->customer, $ticket);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Open);
});

it('moves an open ticket to in progress on a public staff reply, not on a note', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->assigned($this->agent)->create();
    $other = Ticket::factory()->for($this->customer, 'creator')->assigned($this->agent)->create();

    ($this->reply)($this->agent, $ticket);
    ($this->reply)($this->agent, $other, ReplyType::InternalNote);

    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress)
        ->and($other->fresh()->status)->toBe(TicketStatus::Open);
});

it('records the right activity type without the reply text', function (string $who, ReplyType $type, ActivityType $expected) {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->assigned($this->agent)->create();
    $actor = $who === 'agent' ? $this->agent : $this->customer;

    ($this->reply)($actor, $ticket, $type, [], 'SECRET-BODY-TEXT');

    $activity = $ticket->activities()->where('type', $expected)->sole();

    expect($activity->description)->not->toContain('SECRET-BODY-TEXT')
        ->and(json_encode($activity->new_values))->not->toContain('SECRET-BODY-TEXT');
})->with([
    'customer reply' => ['customer', ReplyType::Public, ActivityType::CustomerReplied],
    'agent reply' => ['agent', ReplyType::Public, ActivityType::AgentReplied],
    'internal note' => ['agent', ReplyType::InternalNote, ActivityType::InternalNoteAdded],
]);

it('blocks public replies on closed tickets but allows staff notes', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->closed()->assigned($this->agent)->create();

    expect(fn () => ($this->reply)($this->customer, $ticket))->toThrow(ReplyNotAllowed::class);

    ($this->reply)($this->agent, $ticket, ReplyType::InternalNote);
    expect(TicketReply::query()->sole()->type)->toBe(ReplyType::InternalNote);
});

it('refuses an internal note from a customer even when called directly', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->create();

    expect(fn () => ($this->reply)($this->customer, $ticket, ReplyType::InternalNote))->toThrow(InvalidArgumentException::class);
    expect(TicketReply::count())->toBe(0);
});

it('dispatches ReplyAdded after commit with the reply type', function () {
    Event::fake([ReplyAdded::class]);
    $ticket = Ticket::factory()->for($this->customer, 'creator')->assigned($this->agent)->create();

    ($this->reply)($this->agent, $ticket, ReplyType::InternalNote);

    Event::assertDispatched(ReplyAdded::class, fn (ReplyAdded $e) => $e->type === ReplyType::InternalNote && $e->author->is($this->agent));
});

it('does not dispatch ReplyAdded when the surrounding transaction rolls back', function () {
    $fired = 0;
    Event::listen(ReplyAdded::class, function () use (&$fired) {
        $fired++;
    });
    $ticket = Ticket::factory()->for($this->customer, 'creator')->create();

    try {
        DB::transaction(function () use ($ticket) {
            ($this->reply)($this->customer, $ticket);

            throw new RuntimeException('outer failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($fired)->toBe(0)->and(TicketReply::count())->toBe(0);
});

it('stores attachments privately under tickets/{id}/{Y}/{m}/{uuid}.{ext} with checksum and size', function () {
    $this->travelTo(now()->setDate(2026, 11, 8));
    $ticket = Ticket::factory()->for($this->customer, 'creator')->create();
    $file = UploadedFile::fake()->createWithContent('../../My Invoice.pdf', '%PDF-1.4 hello');

    $reply = ($this->reply)($this->customer, $ticket, ReplyType::Public, [$file]);
    $attachment = Attachment::query()->sole();

    expect($attachment->ticket_reply_id)->toBe($reply->id)
        ->and($attachment->stored_name)->toMatch('#^tickets/'.$ticket->id.'/2026/11/[0-9a-f-]{36}\.pdf$#')
        ->and($attachment->original_name)->toBe('My Invoice.pdf')
        ->and($attachment->size_bytes)->toBe(strlen('%PDF-1.4 hello'))
        ->and($attachment->checksum)->toBe(hash('sha256', '%PDF-1.4 hello'))
        ->and($attachment->disk)->toBe('private')
        ->and(Storage::disk('private')->get($attachment->stored_name))->toBe('%PDF-1.4 hello')
        ->and($ticket->activities()->where('type', ActivityType::CustomerReplied)->sole()->new_values['attachments'])->toBe(1);
});

it('removes stored files when the reply transaction rolls back', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->create();
    $this->mock(TicketActivityRecorder::class)
        ->shouldReceive('record')
        ->andThrow(new RuntimeException('database went away'));

    $files = [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), UploadedFile::fake()->create('b.txt', 1, 'text/plain')];

    expect(fn () => ($this->reply)($this->customer, $ticket, ReplyType::Public, $files))->toThrow(RuntimeException::class);

    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and(Attachment::count())->toBe(0)
        ->and(TicketReply::count())->toBe(0);
});

it('touches the ticket so lists show recent activity', function () {
    $ticket = Ticket::factory()->for($this->customer, 'creator')->create(['updated_at' => now()->subDay()]);

    ($this->reply)($this->customer, $ticket);

    expect($ticket->fresh()->updated_at->isToday())->toBeTrue();
});
