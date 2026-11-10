<?php

use App\Enums\Role;
use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Activity\Models\TicketActivity;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\ImportFinishedNotification;
use App\Modules\Imports\ImportService;
use App\Modules\Imports\Models\TicketImport;
use App\Modules\Imports\ProcessTicketImport;
use App\Modules\Notifications\TicketNotifier;
use App\Modules\Sla\EscalateTicketJob;
use App\Modules\Sla\SlaService;
use App\Modules\Tickets\Enums\TicketPriority;
use App\Modules\Tickets\Enums\TicketSource;
use App\Modules\Tickets\Enums\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\CategorySeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const IMPORT_HEADER = ['external_reference', 'customer_email', 'subject', 'description', 'priority', 'category', 'status', 'assigned_agent_email', 'created_at'];

beforeEach(function () {
    Storage::fake('private');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 06:00:00', 'UTC'));

    $this->admin = User::factory()->admin()->create();
    $this->agent = User::factory()->agent()->create(['email' => 'agent@example.com']);
    Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    Category::factory()->create(['name' => 'Technical Support', 'slug' => 'technical-support']);
});

/** Writes rows (header first) to the private disk line by line and records a pending import. */
function makeImport(User $admin, iterable $rows): TicketImport
{
    $path = 'imports/test-'.uniqid().'.csv';
    Storage::disk('private')->makeDirectory('imports');
    $handle = fopen(Storage::disk('private')->path($path), 'w');

    foreach ($rows as $row) {
        fputcsv($handle, array_values($row));
    }

    fclose($handle);

    return TicketImport::query()->create(['user_id' => $admin->id, 'original_name' => 'legacy.csv', 'path' => $path]);
}

function row(array $overrides = []): array
{
    static $i = 0;
    $i++;

    return array_merge(array_fill_keys(IMPORT_HEADER, ''), [
        'external_reference' => "R-{$i}",
        'customer_email' => 'someone@example.com',
        'subject' => "Subject {$i}",
        'description' => "Description {$i}",
        'priority' => 'medium',
        'category' => 'Billing',
    ], $overrides);
}

/**
 * 10,000 rows with 60 deliberate failures (10 of each):
 *   i % 1000 == 1   invalid email
 *   i % 1000 == 2   bad priority enum
 *   i % 1000 == 7   duplicate of a ticket already in the DB
 *   i % 1000 == 8   unknown category
 *   i % 1000 == 10  duplicate of row i-5 in the same chunk
 *   i % 1000 == 600 duplicate of row i-200, in the previous chunk
 */
function tenThousandRows(): Generator
{
    yield IMPORT_HEADER;

    for ($i = 1; $i <= 10_000; $i++) {
        $row = [
            'external_reference' => "EXT-{$i}",
            'customer_email' => 'customer'.($i % 200).'@example.com',
            'subject' => "Imported ticket {$i}",
            'description' => "Body of imported ticket {$i}",
            'priority' => ['low', 'medium', 'HIGH', 'Critical'][$i % 4],
            'category' => $i % 2 ? 'Billing' : 'technical-support',
            'status' => match (true) {
                $i % 25 === 0 => 'closed',
                $i % 10 === 0 => 'resolved',
                default => '',
            },
            'assigned_agent_email' => $i % 7 === 0 ? 'agent@example.com' : '',
            'created_at' => $i % 3 === 0 ? '2026-11-01T10:00:00+06:00' : '',
        ];

        match ($i % 1000) {
            1 => $row['customer_email'] = 'not-an-email',
            2 => $row['priority'] = 'urgent',
            7 => $row['external_reference'] = 'DB-'.(intdiv($i, 1000) + 1),
            8 => $row['category'] = 'Nonexistent',
            10 => $row['external_reference'] = 'EXT-'.($i - 5),
            600 => $row['external_reference'] = 'EXT-'.($i - 200),
            default => null,
        };

        yield $row;
    }
}

it('imports 10,000 rows in chunks with exact counts, errors and contiguous numbers', function () {
    $this->commitTestTransaction(); // chunks commit as in production; no 20k-row rollback afterwards
    Queue::fake();
    User::factory()->create(['email' => 'customer0@example.com']); // existing customer
    foreach (range(1, 10) as $n) {
        Ticket::factory()->create(['source' => TicketSource::Import, 'external_reference' => "DB-{$n}"]);
    }

    $import = makeImport($this->admin, tenThousandRows());

    memory_reset_peak_usage();
    $before = memory_get_usage();

    app(ImportService::class)->run($import);

    $peakIncrease = memory_get_peak_usage() - $before;
    $import->refresh();

    // Counts
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->total_rows)->toBe(10_000)
        ->and($import->processed_rows)->toBe(10_000)
        ->and($import->success_rows)->toBe(9_940)
        ->and($import->failed_rows)->toBe(60)
        ->and($import->last_processed_row)->toBe(10_000)
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->rowErrors()->count())->toBe(60);

    // Error rows carry the spreadsheet row number (header = 1) and messages.
    $byRow = $import->rowErrors()->get()->keyBy('row_number');
    expect($byRow[2]->errors)->toHaveKey('customer_email')            // data row 1
        ->and($byRow[3]->errors)->toHaveKey('priority')               // data row 2
        ->and($byRow[8]->errors['external_reference'][0])->toContain('already imported')  // row 7: DB duplicate
        ->and($byRow[9]->errors['category'][0])->toBe('Unknown category "Nonexistent".')
        ->and($byRow[11]->errors['external_reference'][0])->toBe('Duplicate external_reference in this file (first seen on row 6).')
        ->and($byRow[601]->errors['external_reference'][0])->toContain('already imported')  // previous chunk
        ->and($byRow[2]->raw_data['customer_email'])->toBe('not-an-email');

    // Tickets: unique, contiguous numbers, one `imported` activity each.
    $imported = Ticket::query()->where('source', TicketSource::Import)->where('external_reference', 'like', 'EXT-%');
    $numbers = (clone $imported)->pluck('ticket_number')->map(fn ($n) => (int) substr($n, strrpos($n, '-') + 1))->sort()->values();

    expect($numbers)->toHaveCount(9_940)
        ->and($numbers->unique())->toHaveCount(9_940)
        ->and($numbers->first())->toBe(1)
        ->and($numbers->last())->toBe(9_940)
        ->and(TicketActivity::query()->where('type', ActivityType::Imported)->count())->toBe(9_940)
        ->and((clone $imported)->distinct()->count('external_reference'))->toBe(9_940);

    // Customers created once, as customers; existing ones reused.
    expect(User::query()->where('email', 'like', 'customer%@example.com')->count())->toBe(200)
        ->and(User::query()->where('email', 'like', 'customer%@example.com')->where('role', '!=', Role::Customer->value)->count())->toBe(0);

    // No per-ticket notifications, emails or jobs; one summary for the uploader.
    Queue::assertNothingPushed();
    Notification::assertNotSentTo(User::query()->where('role', Role::Customer->value)->get(), ResetPassword::class);
    Notification::assertSentTo($this->admin, ImportFinishedNotification::class);
    Notification::assertCount(1);

    // Memory stays bounded (streaming + chunking), independent of file size.
    expect($peakIncrease)->toBeLessThan(64 * 1024 * 1024);
});

it('maps statuses, agents, created_at and SLA for imported rows', function () {
    Queue::fake();
    $import = makeImport($this->admin, [
        IMPORT_HEADER,
        row(['external_reference' => 'S-1', 'status' => 'resolved', 'created_at' => '2026-11-01T10:00:00+06:00']),
        row(['external_reference' => 'S-2', 'status' => 'closed', 'assigned_agent_email' => 'AGENT@example.com']),
        row(['external_reference' => 'S-3', 'priority' => 'CRITICAL', 'created_at' => '2026-10-01T09:00:00Z']), // long overdue
        row(['external_reference' => 'S-4', 'category' => 'technical-support']),
    ]);

    app(ImportService::class)->run($import);

    $tickets = Ticket::query()->where('source', TicketSource::Import)->with('category')->get()->keyBy('external_reference');

    expect($tickets['S-1']->status)->toBe(TicketStatus::Resolved)
        ->and($tickets['S-1']->resolved_at->equalTo($tickets['S-1']->created_at))->toBeTrue()
        ->and($tickets['S-1']->created_at->toDateTimeString())->toBe('2026-11-01 04:00:00')
        ->and($tickets['S-2']->closed_at)->not->toBeNull()
        ->and($tickets['S-2']->assigned_agent_id)->toBe($this->agent->id)
        ->and($tickets['S-3']->priority)->toBe(TicketPriority::Critical)
        ->and($tickets['S-3']->sla_minutes)->toBe(120)
        ->and($tickets['S-3']->breached_notified_at)->not->toBeNull() // pre-marked: no retroactive storm
        ->and($tickets['S-4']->category->slug)->toBe('technical-support');

    // The SLA scan finds nothing to notify for the historical overdue ticket.
    $this->artisan('sla:scan');
    Queue::assertNotPushed(EscalateTicketJob::class, fn ($job) => $job->ticketId === $tickets['S-3']->id);
});

it('resumes from last_processed_row after a crash without duplicates or number gaps', function () {
    config(['support.import.chunk_size' => 500]);
    $rows = (function () {
        yield IMPORT_HEADER;
        foreach (range(1, 1200) as $i) {
            yield row(['external_reference' => "RES-{$i}"]);
        }
    })();
    $import = makeImport($this->admin, $rows);

    // The worker "dies" on row 601 (second chunk).
    $real = app(SlaService::class);
    $this->app->instance(SlaService::class, new class(app(TicketNotifier::class)) extends SlaService
    {
        private int $calls = 0;

        public function calculate(TicketPriority $priority, CarbonInterface $from): array
        {
            if (++$this->calls > 600) {
                throw new RuntimeException('worker died');
            }

            return parent::calculate($priority, $from);
        }
    });

    expect(fn () => app(ImportService::class)->run($import))->toThrow(RuntimeException::class, 'worker died');

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->error_message)->toBe('worker died')
        ->and($import->last_processed_row)->toBe(500)
        ->and($import->success_rows)->toBe(500)
        ->and(Ticket::query()->where('source', TicketSource::Import)->count())->toBe(500);

    // Retry (as queue:retry or the Resume button would).
    $this->app->instance(SlaService::class, $real);
    app(ImportService::class)->run($import);

    $import->refresh();
    $numbers = Ticket::query()->where('source', TicketSource::Import)->pluck('ticket_number')
        ->map(fn ($n) => (int) substr($n, strrpos($n, '-') + 1))->sort()->values();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->success_rows)->toBe(1200)
        ->and($import->failed_rows)->toBe(0)
        ->and($numbers->all())->toBe(range(1, 1200)); // rolled-back chunk left no gap
});

it('cannot duplicate tickets when committed chunks are processed again', function () {
    $import = makeImport($this->admin, [IMPORT_HEADER, row(['external_reference' => 'AGAIN-1']), row(['external_reference' => 'AGAIN-2'])]);
    app(ImportService::class)->run($import);

    $import->refresh()->forceFill(['status' => ImportStatus::Failed, 'last_processed_row' => 0])->save();
    app(ImportService::class)->run($import);

    expect(Ticket::query()->where('source', TicketSource::Import)->count())->toBe(2)
        ->and($import->refresh()->failed_rows)->toBe(2); // the replays are reported as duplicates
});

it('fails the import when required columns are missing', function () {
    $import = makeImport($this->admin, [['external_reference', 'subject'], ['X-1', 'Hi']]);

    app(ImportService::class)->run($import);

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->error_message)->toBe('Missing required columns: customer_email, description, priority, category.');
});

it('reports rows with the wrong number of columns, staff emails and unknown agents', function () {
    $staff = User::factory()->agent()->create(['email' => 'staff@example.com']);
    $import = makeImport($this->admin, [
        IMPORT_HEADER,
        ['too', 'few'],
        row(['external_reference' => 'E-2', 'customer_email' => 'staff@example.com']),
        row(['external_reference' => 'E-3', 'assigned_agent_email' => 'ghost@example.com']),
        row(['external_reference' => 'E-4']),
    ]);

    app(ImportService::class)->run($import);

    $errors = $import->rowErrors()->get()->keyBy('row_number');

    expect($import->refresh()->success_rows)->toBe(1)
        ->and($errors[2]->errors)->toHaveKey('row')
        ->and($errors[3]->errors['customer_email'][0])->toBe('This email belongs to a staff account.')
        ->and($errors[4]->errors['assigned_agent_email'][0])->toBe('No agent account has this email.');
});

it('sends new customers a password-reset link only when the flag is set', function () {
    config(['support.import.welcome_new_customers' => true]);
    $import = makeImport($this->admin, [IMPORT_HEADER, row(['customer_email' => 'fresh@example.com'])]);

    app(ImportService::class)->run($import);

    Notification::assertSentTo(User::query()->where('email', 'fresh@example.com')->sole(), ResetPassword::class);
});

it('imports the documented sample file', function () {
    $this->seed(CategorySeeder::class);
    User::factory()->agent()->create(['email' => 'agent1@example.com']);
    User::factory()->agent()->create(['email' => 'agent2@example.com']);
    $path = 'imports/sample.csv';
    Storage::disk('private')->put($path, file_get_contents(resource_path('samples/ticket-import-sample.csv')));
    $import = TicketImport::query()->create(['user_id' => $this->admin->id, 'original_name' => 'sample.csv', 'path' => $path]);

    app(ImportService::class)->run($import);

    expect($import->refresh()->success_rows)->toBe(5)->and($import->failed_rows)->toBe(0);
});

it('runs as one long, single-try job on the imports queue and marks failure', function () {
    $job = new ProcessTicketImport(5);

    expect($job->queue)->toBe('imports')
        ->and($job->timeout)->toBe(3600)
        ->and($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue();

    $import = TicketImport::factory()->create();
    (new ProcessTicketImport($import->id))->failed(new RuntimeException('Job timed out'));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed)->and($import->error_message)->toBe('Job timed out');
});
