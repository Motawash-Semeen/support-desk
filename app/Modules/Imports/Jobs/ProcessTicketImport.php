<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Actions\RunTicketImport;
use App\Modules\Imports\Models\TicketImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one CSV import on the `imports` queue. One sequential job with a
 * checkpoint: tries = 1 (a crash marks the import failed; `queue:retry` or the
 * Resume button continues from last_processed_row). The queue connection's
 * retry_after must exceed $timeout (see config/queue.php imports-*).
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

    public function handle(RunTicketImport $run): void
    {
        $import = TicketImport::query()->find($this->importId);

        if ($import !== null) {
            $run($import);
        }
    }

    /** Timeouts and worker kills land here without passing through the action. */
    public function failed(Throwable $exception): void
    {
        if (TicketImport::query()->whereKey($this->importId)->exists()) {
            RunTicketImport::markFailed($this->importId, $exception->getMessage(), $exception);
        }
    }
}
