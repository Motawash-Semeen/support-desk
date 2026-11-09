<?php

use App\Models\User;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Conversation\Models\TicketReply;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** A real temp file, so the MIME type is sniffed from its CONTENT (finfo), not its name. */
function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function () {
    Storage::fake('private');
    config(['support.assignment.auto_on_create' => false]);

    $this->customer = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->otherAgent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->for($this->customer, 'creator')->inProgress()->assigned($this->agent)->create();
    $this->url = route('tickets.replies.store', $this->ticket);
});

it('posts a customer reply', function () {
    $this->actingAs($this->customer)
        ->post($this->url, ['body' => 'Any update?'])
        ->assertRedirect(route('tickets.show', $this->ticket).'#reply-'.TicketReply::query()->sole()->id);

    expect(TicketReply::query()->sole()->type)->toBe(ReplyType::Public);
});

it('rejects a customer who tampers the form to post an internal note', function () {
    $this->actingAs($this->customer)
        ->post($this->url, ['body' => 'sneaky', 'type' => 'internal_note'])
        ->assertSessionHasErrors(['type' => 'Only staff can add internal notes.']);

    $this->actingAs($this->customer)
        ->postJson($this->url, ['body' => 'sneaky', 'type' => 'internal_note'])
        ->assertUnprocessable();

    expect(TicketReply::count())->toBe(0);
});

it('lets the assigned agent add an internal note', function () {
    $this->actingAs($this->agent)->post($this->url, ['body' => 'Check logs', 'type' => 'internal_note'])->assertRedirect();

    expect(TicketReply::query()->sole()->type)->toBe(ReplyType::InternalNote);
});

it('returns 404 to an agent the ticket is not assigned to', function (string $type) {
    $this->actingAs($this->otherAgent)->post($this->url, ['body' => 'x', 'type' => $type])->assertNotFound();
})->with(['public', 'internal_note']);

it('lets an agent open a queue ticket but not reply before accepting it', function (string $type) {
    $queued = Ticket::factory()->for($this->customer, 'creator')->unassigned()->create();

    $this->actingAs($this->otherAgent)->get(route('tickets.show', $queued))->assertOk();
    $this->actingAs($this->otherAgent)->post(route('tickets.replies.store', $queued), ['body' => 'x', 'type' => $type])->assertForbidden();
})->with(['public', 'internal_note']);

it('returns 404 to another customer', function () {
    $this->actingAs(User::factory()->create())->post($this->url, ['body' => 'x'])->assertNotFound();
});

it('blocks replies on a closed ticket', function () {
    $closed = Ticket::factory()->for($this->customer, 'creator')->closed()->assigned($this->agent)->create();

    $this->actingAs($this->customer)->post(route('tickets.replies.store', $closed), ['body' => 'hello?'])->assertForbidden();
    $this->actingAs($this->customer)->get(route('tickets.show', $closed))->assertSee('This ticket is closed. Reopen it to reply.');
});

it('accepts allowed attachments', function () {
    $this->actingAs($this->customer)->post($this->url, [
        'body' => 'Screenshots attached',
        'attachments' => [
            UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('screen.png', 50, 'image/png'),
            realUpload('notes.txt', 'plain text notes'),
        ],
    ])->assertSessionHasNoErrors();

    expect(Attachment::count())->toBe(3)->and(Storage::disk('private')->allFiles())->toHaveCount(3);
});

it('rejects dangerous or invalid uploads', function (Closure $file) {
    $this->actingAs($this->customer)
        ->post($this->url, ['body' => 'see file', 'attachments' => [$file()]])
        ->assertSessionHasErrors('attachments.0');

    expect(TicketReply::count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'php script' => [fn () => realUpload('shell.php', '<?php system($_GET["c"]); ?>')],
    'php renamed to .pdf (content check)' => [fn () => realUpload('invoice.pdf', '<?php system($_GET["c"]); ?>')],
    'double extension invoice.php.pdf' => [fn () => UploadedFile::fake()->create('invoice.php.pdf', 10, 'application/pdf')],
    'double extension invoice.pdf.exe' => [fn () => UploadedFile::fake()->create('invoice.pdf.exe', 10, 'application/pdf')],
    'svg' => [fn () => realUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
    'html' => [fn () => realUpload('page.html', '<html><script>alert(1)</script></html>')],
    'oversize (5121 KB)' => [fn () => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')],
    'empty file' => [fn () => UploadedFile::fake()->create('empty.txt', 0, 'text/plain')],
]);

it('rejects more than the configured number of files', function () {
    $files = collect(range(1, 6))->map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 1, 'application/pdf'))->all();

    $this->actingAs($this->customer)
        ->post($this->url, ['body' => 'many', 'attachments' => $files])
        ->assertSessionHasErrors('attachments');
});

it('stores ticket-level attachments when a ticket is created', function () {
    $category = Category::factory()->create();

    $this->actingAs($this->customer)->post(route('tickets.store'), [
        'subject' => 'Printer', 'description' => 'Jammed', 'priority' => 'low', 'category_id' => $category->id,
        'attachments' => [UploadedFile::fake()->create('photo.jpg', 20, 'image/jpeg')],
    ])->assertSessionHasNoErrors();

    $attachment = Attachment::query()->sole();
    expect($attachment->ticket_reply_id)->toBeNull()
        ->and($attachment->ticket_id)->toBe(Ticket::query()->latest('id')->value('id'));
});

it('validates attachments on ticket creation too', function () {
    $this->actingAs($this->customer)->post(route('tickets.store'), [
        'subject' => 'x', 'description' => 'y', 'priority' => 'low', 'category_id' => Category::factory()->create()->id,
        'attachments' => [realUpload('run.sh', "#!/bin/sh\nrm -rf /")],
    ])->assertSessionHasErrors('attachments.0');
});
