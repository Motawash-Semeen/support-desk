<?php

use App\Enums\ImportStatus;
use App\Jobs\ProcessTicketImport;
use App\Models\TicketImport;
use App\Models\TicketImportError;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
});

it('stores the upload privately, records a pending import, queues the job and redirects', function () {
    $file = UploadedFile::fake()->createWithContent('legacy tickets.csv', "external_reference,customer_email\nA,a@example.com\n");

    $response = $this->actingAs($this->admin)->post(route('admin.imports.store'), ['file' => $file]);

    $import = TicketImport::query()->sole();

    $response->assertRedirect(route('admin.imports.show', $import));
    expect($import->status)->toBe(ImportStatus::Pending)
        ->and($import->original_name)->toBe('legacy tickets.csv')
        ->and($import->path)->toMatch('#^imports/[0-9a-f-]{36}\.csv$#')
        ->and($import->user_id)->toBe($this->admin->id);
    Storage::disk('private')->assertExists($import->path);

    Queue::assertPushedOn('imports', ProcessTicketImport::class, fn ($job) => $job->importId === $import->id);
});

it('is admin only', function (string $role) {
    $user = User::factory()->{$role}()->create();
    $import = TicketImport::factory()->create();

    $this->actingAs($user)->get(route('admin.imports.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.imports.store'), ['file' => UploadedFile::fake()->createWithContent('a.csv', "a\n1\n")])->assertForbidden();
    $this->actingAs($user)->get(route('admin.imports.status', $import))->assertForbidden();
    $this->actingAs($user)->get(route('admin.imports.errors', $import))->assertForbidden();
})->with(['agent', 'customer']);

it('rejects non-CSV and oversized files', function () {
    config(['support.import.max_kb' => 1]);

    $this->actingAs($this->admin)
        ->post(route('admin.imports.store'), ['file' => UploadedFile::fake()->create('tickets.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');

    $this->actingAs($this->admin)
        ->post(route('admin.imports.store'), ['file' => UploadedFile::fake()->createWithContent('big.csv', str_repeat("a,b\n", 2000))])
        ->assertSessionHasErrors('file');

    Queue::assertNothingPushed();
});

it('reports live progress as JSON', function () {
    $import = TicketImport::factory()->create([
        'status' => ImportStatus::Processing, 'total_rows' => 200, 'processed_rows' => 50, 'success_rows' => 45, 'failed_rows' => 5,
    ]);

    $this->actingAs($this->admin)->getJson(route('admin.imports.status', $import))
        ->assertOk()
        ->assertExactJson([
            'status' => 'processing', 'finished' => false, 'total' => 200, 'processed' => 50, 'success' => 45, 'failed' => 5,
            'percent' => 25, 'error_message' => null, 'errors_url' => route('admin.imports.errors', $import),
        ]);

    $this->actingAs($this->admin)->get(route('admin.imports.show', $import))
        ->assertOk()
        ->assertSee(str_replace('/', '\/', route('admin.imports.status', $import)), false) // inside @js()
        ->assertSee('progressbar', false);
});

it('streams the error report as CSV and neutralises formulas', function () {
    $import = TicketImport::factory()->completed(1, 2)->create();
    TicketImportError::factory()->for($import, 'import')->create([
        'row_number' => 7,
        'raw_data' => ['external_reference' => 'X-7', 'customer_email' => 'bad', 'subject' => '=HYPERLINK("http://evil")'],
        'errors' => ['customer_email' => ['The customer email field must be a valid email address.']],
    ]);
    TicketImportError::factory()->for($import, 'import')->create(['row_number' => 3, 'raw_data' => ['too', 'few'], 'errors' => ['row' => ['Wrong columns.']]]);

    $response = $this->actingAs($this->admin)->get(route('admin.imports.errors', $import))->assertOk();
    $csv = $response->streamedContent();
    $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

    expect($response->headers->get('Content-Disposition'))->toContain("import-{$import->id}-errors.csv")
        ->and($lines[0])->toBe(['row', 'errors', 'external_reference', 'customer_email', 'subject', 'description', 'priority', 'category', 'status', 'assigned_agent_email', 'created_at'])
        ->and($lines[1][0])->toBe('3') // ordered by row number
        ->and($lines[2][0])->toBe('7')
        ->and($lines[2][1])->toBe('customer_email: The customer email field must be a valid email address.')
        ->and($lines[2][4])->toBe("'=HYPERLINK(\"http://evil\")");
});

it('offers the sample file', function () {
    $this->actingAs($this->admin)->get(route('admin.imports.sample'))
        ->assertOk()
        ->assertDownload('ticket-import-sample.csv');
});

it('resumes a failed import and refuses to rerun a completed one', function () {
    $failed = TicketImport::factory()->create(['status' => ImportStatus::Failed, 'last_processed_row' => 500]);
    $done = TicketImport::factory()->completed()->create();

    $this->actingAs($this->admin)->post(route('admin.imports.resume', $failed))
        ->assertRedirect(route('admin.imports.show', $failed))
        ->assertSessionHas('status', 'Import resumed from row 501.');
    $this->actingAs($this->admin)->post(route('admin.imports.resume', $done))->assertSessionHas('status', 'This import is already complete.');

    Queue::assertPushed(ProcessTicketImport::class, 1);
});

it('lists past imports with their uploader', function () {
    TicketImport::factory()->completed(9, 1)->create(['user_id' => $this->admin->id, 'original_name' => 'october.csv']);

    $this->actingAs($this->admin)->get(route('admin.imports.index'))
        ->assertOk()
        ->assertSee('october.csv')
        ->assertSee($this->admin->name)
        ->assertSee(route('admin.imports.sample'));
});
