<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Imports\ImportTicketRows;
use App\Actions\Imports\StartTicketImport;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketImportRequest;
use App\Jobs\ProcessTicketImport;
use App\Models\Ticket;
use App\Models\TicketImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin CSV import: upload (queued, returns at once), live status, streamed
 * error report, sample file, and resume after a failure.
 */
class TicketImportController extends Controller
{
    public function index(): View
    {
        $this->authorize('import', Ticket::class);

        return view('admin.imports.index', [
            'imports' => TicketImport::query()->with('user:id,name')->latest()->latest('id')->paginate(10),
            'columns' => [...ImportTicketRows::REQUIRED_COLUMNS, ...ImportTicketRows::OPTIONAL_COLUMNS],
        ]);
    }

    public function store(StoreTicketImportRequest $request, StartTicketImport $startImport): RedirectResponse
    {
        $this->authorize('import', Ticket::class);

        $import = $startImport($request->user(), $request->file('file'));

        return redirect()->route('admin.imports.show', $import)->with('status', __('Import queued. This page updates automatically.'));
    }

    public function show(TicketImport $import): View
    {
        $this->authorize('import', Ticket::class);

        return view('admin.imports.show', ['import' => $import]);
    }

    /** Polled by the status page every 3 seconds. */
    public function status(TicketImport $import): JsonResponse
    {
        $this->authorize('import', Ticket::class);

        return response()->json([
            'status' => $import->status->value,
            'finished' => $import->status->isFinished(),
            'total' => $import->total_rows,
            'processed' => $import->processed_rows,
            'success' => $import->success_rows,
            'failed' => $import->failed_rows,
            'percent' => $import->total_rows > 0 ? (int) floor($import->processed_rows / $import->total_rows * 100) : 0,
            'error_message' => $import->error_message,
            'errors_url' => $import->failed_rows > 0 ? route('admin.imports.errors', $import) : null,
        ]);
    }

    /** Streams the row errors as CSV with cursor(): constant memory for any size. */
    public function errors(TicketImport $import): StreamedResponse
    {
        $this->authorize('import', Ticket::class);

        $columns = [...ImportTicketRows::REQUIRED_COLUMNS, ...ImportTicketRows::OPTIONAL_COLUMNS];

        return response()->streamDownload(function () use ($import, $columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'errors', ...$columns]);

            foreach ($import->rowErrors()->orderBy('row_number')->cursor() as $error) {
                $messages = collect($error->errors)
                    ->map(fn (array $list, string $field) => $field.': '.implode(' ', $list))
                    ->implode('; ');
                $raw = $error->raw_data;
                $values = array_is_list($raw) ? $raw : array_map(fn ($c) => $raw[$c] ?? '', $columns);

                fputcsv($out, array_map([self::class, 'safeCell'], [$error->row_number, $messages, ...$values]));
            }

            fclose($out);
        }, "import-{$import->id}-errors.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function sample(): BinaryFileResponse
    {
        $this->authorize('import', Ticket::class);

        return response()->download(resource_path('samples/ticket-import-sample.csv'), 'ticket-import-sample.csv', ['Content-Type' => 'text/csv']);
    }

    /** Re-queue a failed (or stuck) import; it continues from last_processed_row. */
    public function resume(Request $request, TicketImport $import): RedirectResponse
    {
        $this->authorize('import', Ticket::class);

        if ($import->status === ImportStatus::Completed) {
            return back()->with('status', __('This import is already complete.'));
        }

        ProcessTicketImport::dispatch($import->id);

        return redirect()->route('admin.imports.show', $import)->with('status', __('Import resumed from row :row.', ['row' => $import->last_processed_row + 1]));
    }

    /**
     * Neutralise spreadsheet formulas in the report (CSV injection): cells that
     * start with = + - @ or a tab/CR are prefixed with a quote.
     */
    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
