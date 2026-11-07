<?php

use App\Models\User;
use App\Modules\Activity\Enums\ActivityType;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/*
| Real concurrency: 20 separate PHP processes race to claim one ticket on the
| MySQL test database. Rows must be COMMITTED so other processes can see
| them, so this suite does not use RefreshDatabase and cleans up after itself.
| Skipped on SQLite (in-memory databases are private to one process).
*/

const RACERS = 20;

it('lets exactly one of 20 concurrent processes claim the ticket', function () {
    if (! Schema::hasTable('tickets')) {
        $this->artisan('migrate', ['--force' => true]);
    }

    $customer = User::factory()->create();
    $category = Category::factory()->create();
    $agents = User::factory()->agent()->count(RACERS)->create();
    $ticket = Ticket::factory()->for($customer, 'creator')->for($category)->create();

    try {
        $connection = config('database.connections.mysql');
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'],
            'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];
        $startAt = microtime(true) + 8; // every worker boots, then all fire at once

        $processes = $agents->map(function (User $agent) use ($ticket, $env, $startAt) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/claim-worker.php'), (string) $ticket->id, (string) $agent->id, (string) $startAt],
                base_path(),
                $env,
                null,
                90,
            );
            $process->start();

            return $process;
        });

        $results = $processes->map(function (Process $process) {
            $process->wait();

            return trim($process->getOutput()) ?: 'ERROR: '.$process->getErrorOutput();
        });

        $winners = $results->filter(fn (string $r) => $r === 'WON');

        expect($results->reject(fn (string $r) => in_array($r, ['WON', 'LOST'], true))->all())->toBe([])
            ->and($winners)->toHaveCount(1)
            ->and($results->filter(fn (string $r) => $r === 'LOST'))->toHaveCount(RACERS - 1);

        $winner = $agents->values()[$winners->keys()->first()];
        $fresh = $ticket->fresh();

        expect($fresh->assigned_agent_id)->toBe($winner->id)
            ->and($fresh->activities()->where('type', ActivityType::Assigned)->count())->toBe(1);
    } finally {
        DB::table('tickets')->where('id', $ticket->id)->delete(); // cascades activities
        User::query()->whereKey($agents->modelKeys())->delete();
        $customer->delete();
        $category->delete();
    }
})
    ->group('concurrency')
    ->skip(fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'Needs a shared MySQL database (SQLite :memory: is per process).');
