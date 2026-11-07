<?php

use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    $this->owner = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->for($this->owner, 'creator')->assigned($this->agent)->create();

    $this->store = function (?TicketReply $reply = null): Attachment {
        $attachment = Attachment::factory()->create([
            'ticket_id' => $this->ticket->id,
            'ticket_reply_id' => $reply?->id,
            'uploaded_by' => $reply?->user_id ?? $this->owner->id,
            'original_name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
        ]);
        Storage::disk('private')->put($attachment->path(), '%PDF-1.4 test');

        return $attachment;
    };
});

it('lets the owner download a ticket attachment from the private disk', function () {
    $attachment = ($this->store)();

    $this->actingAs($this->owner)
        ->get(route('attachments.download', $attachment))
        ->assertOk()
        ->assertDownload('invoice.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('hides internal-note attachments from the customer but not from staff', function () {
    $note = TicketReply::factory()->for($this->ticket)->internalNote()->create(['user_id' => $this->agent->id]);
    $attachment = ($this->store)($note);
    $url = route('attachments.download', $attachment);

    $this->actingAs($this->owner)->get($url)->assertNotFound();
    $this->actingAs($this->agent)->get($url)->assertOk();
});

it('returns 404 to another customer', function () {
    $attachment = ($this->store)();

    $this->actingAs(User::factory()->create())
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});

it('returns 404 for an attachment id that does not exist', function () {
    $this->actingAs($this->owner)->get('/attachments/999999/download')->assertNotFound();
});

it('returns 404 and logs when the file is missing on disk', function () {
    $attachment = ($this->store)();
    Storage::disk('private')->delete($attachment->path());

    $this->actingAs($this->owner)
        ->get(route('attachments.download', $attachment))
        ->assertNotFound();
});

it('requires authentication', function () {
    $attachment = ($this->store)();

    $this->get(route('attachments.download', $attachment))->assertRedirect(route('login'));
});
