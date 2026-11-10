<?php

namespace App\Modules\Imports;

use App\Modules\Imports\Models\TicketImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one import on the `imports` queue. tries = 1: a crash marks it failed and
 * a retry resumes from the checkpoint. The connection's retry_after (config/queue.php)
 * must exceed $timeout.
 */
class ProcessTicketImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue((string) config('support.import.queue'));

        if ($connection = config('support.import.queue_connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(ImportService $imports): void
    {
        $import = TicketImport::query()->find($this->importId);

        if ($import !== null) {
            $imports->run($import);
        }
    }

    /** Timeouts and killed workers end up here, not in run(). */
    public function failed(Throwable $exception): void
    {
        if (TicketImport::query()->whereKey($this->importId)->exists()) {
            ImportService::markFailed($this->importId, $exception->getMessage(), $exception);
        }
    }
}
