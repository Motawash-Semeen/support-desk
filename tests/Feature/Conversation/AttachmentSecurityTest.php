<?php

use App\Models\User;
use App\Modules\Conversation\Actions\AddReply;
use App\Modules\Conversation\Enums\ReplyType;
use App\Modules\Conversation\Models\Attachment;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    $this->owner = User::factory()->create();
    $this->agent = User::factory()->agent()->create();
    $this->ticket = Ticket::factory()->for($this->owner, 'creator')->inProgress()->assigned($this->agent)->create();
    $this->upload = function (User $actor, ReplyType $type, UploadedFile $file, ?Ticket $ticket = null): Attachment {
        $reply = app(AddReply::class)($actor, $ticket ?? $this->ticket, 'see attached', $type, [$file]);

        return $reply->attachments()->sole();
    };
});

it('lets the owner download their files and staff download everything', function () {
    $public = ($this->upload)($this->owner, ReplyType::Public, UploadedFile::fake()->create('invoice.pdf', 5, 'application/pdf'));
    $note = ($this->upload)($this->agent, ReplyType::InternalNote, UploadedFile::fake()->create('notes.txt', 1, 'text/plain'));

    $this->actingAs($this->owner)->get(route('attachments.download', $public))->assertOk()->assertDownload('invoice.pdf');
    $this->actingAs($this->agent)->get(route('attachments.download', $note))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('attachments.download', $note))->assertOk();
});

it('returns 404 to a customer for an internal note file or another ticket file', function () {
    $note = ($this->upload)($this->agent, ReplyType::InternalNote, UploadedFile::fake()->create('notes.txt', 1, 'text/plain'));
    $otherCustomer = User::factory()->create();
    $otherTicket = Ticket::factory()->for($otherCustomer, 'creator')->create();
    $foreign = ($this->upload)($otherCustomer, ReplyType::Public, UploadedFile::fake()->create('theirs.pdf', 5, 'application/pdf'), $otherTicket);

    $this->actingAs($this->owner)->get(route('attachments.download', $note))->assertNotFound();
    $this->actingAs($this->owner)->get(route('attachments.download', $foreign))->assertNotFound();
});

it('sends safe headers and a cleaned file name, never the storage path', function () {
    $attachment = ($this->upload)($this->owner, ReplyType::Public, UploadedFile::fake()->create('report.pdf', 5, 'application/pdf'));
    $attachment->forceFill(['original_name' => "../../etc/pass\"wd;\r\n.pdf"])->save();

    $response = $this->actingAs($this->owner)->get(route('attachments.download', $attachment))->assertOk();
    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment;')
        ->and($disposition)->toContain('pass_wd_.pdf')
        ->and($disposition)->not->toContain('..')
        ->and($disposition)->not->toContain('tickets/')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('displays only PNG and JPEG inline, and only on request', function () {
    $png = ($this->upload)($this->owner, ReplyType::Public, UploadedFile::fake()->create('screen.png', 5, 'image/png'));
    $pdf = ($this->upload)($this->owner, ReplyType::Public, UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'));

    $disposition = fn (Attachment $a, array $query = []) => $this->actingAs($this->owner)
        ->get(route('attachments.download', ['attachment' => $a] + $query))
        ->headers->get('Content-Disposition');

    expect($disposition($png, ['inline' => 1]))->toStartWith('inline;')
        ->and($disposition($png))->toStartWith('attachment;')
        ->and($disposition($pdf, ['inline' => 1]))->toStartWith('attachment;');
});

/* attachments:purge-orphans -------------------------------------------- */

it('purges old files with no database row and keeps everything else', function () {
    $disk = Storage::disk('private');
    $kept = ($this->upload)($this->owner, ReplyType::Public, UploadedFile::fake()->create('kept.pdf', 5, 'application/pdf'));
    $oldOrphan = 'tickets/'.$this->ticket->id.'/2026/10/old-orphan.pdf';
    $newOrphan = 'tickets/'.$this->ticket->id.'/2026/11/new-orphan.pdf';
    $disk->put($oldOrphan, 'x');
    $disk->put($newOrphan, 'x');
    // File times are set from the frozen clock, never the real one.
    touch($disk->path($oldOrphan), now()->subHours(30)->getTimestamp());
    touch($disk->path($newOrphan), now()->subHour()->getTimestamp());
    touch($disk->path($kept->stored_name), now()->subHours(30)->getTimestamp());

    $this->artisan('attachments:purge-orphans', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 orphan file(s)')
        ->assertSuccessful();
    expect($disk->exists($oldOrphan))->toBeTrue();

    $this->artisan('attachments:purge-orphans')->expectsOutputToContain('Deleted 1 orphan file(s)')->assertSuccessful();

    expect($disk->exists($oldOrphan))->toBeFalse()
        ->and($disk->exists($newOrphan))->toBeTrue()     // too new: may be mid-transaction
        ->and($disk->exists($kept->stored_name))->toBeTrue(); // has a row
});

it('schedules the orphan purge daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'attachments:purge-orphans'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 3 * * *');
});
