<?php

namespace App\Modules\Imports;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\TicketImport;
use App\Modules\Imports\Models\TicketImportError;
use App\Modules\Imports\Requests\StoreTicketImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin CSV import screens: upload, live status, error report, sample file and resume. */
class TicketImportController extends Controller
{
    public function index(): View
    {
        return view('Imports::index', [
            'imports' => TicketImport::query()->with('user:id,name')->latest()->latest('id')->paginate(10),
            'columns' => [...ImportService::REQUIRED_COLUMNS, ...ImportService::OPTIONAL_COLUMNS],
        ]);
    }

    public function store(StoreTicketImportRequest $request, ImportService $imports): RedirectResponse
    {
        $import = $imports->start($request->user(), $request->file('file'));

        return redirect()->route('admin.imports.show', $import)->with('status', __('Import queued. This page updates automatically.'));
    }

    public function show(TicketImport $import): View
    {
        return view('Imports::show', ['import' => $import]);
    }

    /** Polled by the status page every 3 seconds. */
    public function status(TicketImport $import): JsonResponse
    {
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

    /** Streams the row errors as a CSV file. */
    public function errors(TicketImport $import): StreamedResponse
    {
        $columns = [...ImportService::REQUIRED_COLUMNS, ...ImportService::OPTIONAL_COLUMNS];

        return response()->streamDownload(function () use ($import, $columns): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'errors', ...$columns]);

            foreach (TicketImportError::query()->where('ticket_import_id', $import->id)->orderBy('row_number')->cursor() as $error) {
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
        return response()->download(resource_path('samples/ticket-import-sample.csv'), 'ticket-import-sample.csv', ['Content-Type' => 'text/csv']);
    }

    /** Re-queue a failed (or stuck) import; it continues from last_processed_row. */
    public function resume(Request $request, TicketImport $import): RedirectResponse
    {
        if ($import->status === ImportStatus::Completed) {
            return back()->with('status', __('This import is already complete.'));
        }

        ProcessTicketImport::dispatch($import->id);

        return redirect()->route('admin.imports.show', $import)->with('status', __('Import resumed from row :row.', ['row' => $import->last_processed_row + 1]));
    }

    /** Prefixes cells that start with = + - @ so spreadsheets do not run them as formulas. */
    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
