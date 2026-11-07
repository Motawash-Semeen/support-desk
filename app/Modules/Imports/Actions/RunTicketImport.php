<?php

namespace App\Modules\Imports\Actions;

use App\Models\User;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\TicketImport;
use App\Modules\Imports\Notifications\TicketImportFinishedNotification;
use App\Modules\Imports\Support\CsvReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Runs (or resumes) one import: streams the file, skips the rows already
 * committed (last_processed_row), and hands chunks to ImportTicketRows. Each
 * chunk commits its own checkpoint, so a crash or a manual retry continues
 * where the last committed chunk ended; a chunk that is processed twice cannot
 * create duplicates thanks to the unique (source, external_reference) index.
 */
final class RunTicketImport
{
    public function __construct(private readonly ImportTicketRows $importRows) {}

    public function __invoke(TicketImport $import): TicketImport
    {
        $import->refresh();

        if ($import->status === ImportStatus::Completed) {
            return $import;
        }

        try {
            $reader = new CsvReader(Storage::disk((string) config('support.attachments.disk'))->path($import->path));

            $missing = array_values(array_diff(ImportTicketRows::REQUIRED_COLUMNS, $reader->header()));
            if ($missing !== []) {
                return self::markFailed($import->id, 'Missing required columns: '.implode(', ', $missing).'.');
            }

            $import->forceFill([
                'status' => ImportStatus::Processing,
                'started_at' => $import->started_at ?? now(),
                'error_message' => null,
                // One cheap streaming pass, only the first time.
                'total_rows' => $import->total_rows > 0 ? $import->total_rows : $reader->count(),
            ])->save();

            $processed = $import->last_processed_row;

            $reader->rows()
                ->skip($processed)
                ->chunk(max(1, (int) config('support.import.chunk_size')))
                ->each(function (LazyCollection $chunk) use ($import, &$processed): void {
                    $rows = collect($chunk->all());
                    $processed += $rows->count();

                    ($this->importRows)($import, $rows, $processed);

                    unset($rows, $chunk); // keep memory flat between chunks
                });

            $import->refresh()->forceFill(['status' => ImportStatus::Completed, 'finished_at' => now()])->save();

            User::query()->find($import->user_id)?->notify(new TicketImportFinishedNotification($import));

            return $import;
        } catch (Throwable $e) {
            self::markFailed($import->id, $e->getMessage(), $e);

            throw $e;
        }
    }

    /** Status failed with a message; counters and the checkpoint stay, so a retry resumes. */
    public static function markFailed(int $importId, string $message, ?Throwable $e = null): TicketImport
    {
        $import = TicketImport::query()->findOrFail($importId);

        $import->forceFill([
            'status' => ImportStatus::Failed,
            'error_message' => mb_strimwidth($message, 0, 1000, '…'),
            'finished_at' => now(),
        ])->save();

        Log::error('Ticket import failed.', [
            'import_id' => $importId,
            'last_processed_row' => $import->last_processed_row,
            'message' => $message,
            'exception' => $e === null ? null : $e::class,
        ]);

        return $import;
    }
}
