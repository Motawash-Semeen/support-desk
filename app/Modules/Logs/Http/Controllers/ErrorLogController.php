<?php

namespace App\Modules\Logs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logs\Models\ErrorLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin error log: errors recorded by App\Modules\Logs\Services\ErrorRecorder.
 * Read-mostly; the only writes are resolve/reopen and delete.
 */
class ErrorLogController extends Controller
{
    private const LIST_COLUMNS = ['id', 'level', 'source', 'message', 'exception_class', 'status_code', 'url', 'occurrences', 'first_seen_at', 'last_seen_at', 'resolved_at'];

    public function index(Request $request): View
    {
        $this->authorize('view-error-log');

        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'all'])],
            'level' => ['nullable', Rule::in(ErrorLog::LEVELS)],
            'source' => ['nullable', Rule::in(['exception', 'log', 'http'])],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $status = $filters['status'] ?? 'open';

        // Not `$errors`: that name is the validation error bag in every view.
        $entries = ErrorLog::query()
            ->select(self::LIST_COLUMNS)
            ->when($status === 'open', fn (Builder $q) => $q->open())
            ->when($status === 'resolved', fn (Builder $q) => $q->resolved())
            ->when($filters['level'] ?? null, fn (Builder $q, string $level) => $q->where('level', $level))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('message', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('exception_class', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $summary = ErrorLog::query()
            ->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END), 0) AS open_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN resolved_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS resolved_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END), 0) AS last_day', [now()->subDay()])
            ->first();

        return view('Logs::index', [
            'entries' => $entries,
            'filters' => $filters,
            'status' => $status,
            'summary' => $summary,
        ]);
    }

    public function show(ErrorLog $errorLog): View
    {
        $this->authorize('view-error-log');

        $errorLog->load(['user:id,name,email', 'resolver:id,name']);

        return view('Logs::show', ['error' => $errorLog]);
    }

    public function resolve(Request $request, ErrorLog $errorLog): RedirectResponse
    {
        $this->authorize('view-error-log');

        $resolving = ! $errorLog->isResolved();
        $errorLog->forceFill([
            'resolved_at' => $resolving ? now() : null,
            'resolved_by' => $resolving ? $request->user()->id : null,
        ])->save();

        return back()->with('status', $resolving ? __('Marked as resolved. It reopens automatically if it happens again.') : __('Reopened.'));
    }

    public function destroy(ErrorLog $errorLog): RedirectResponse
    {
        $this->authorize('view-error-log');

        $errorLog->delete();

        return redirect()->route('admin.errors.index')->with('status', __('Error entry deleted.'));
    }

    public function clearResolved(): RedirectResponse
    {
        $this->authorize('view-error-log');

        $deleted = ErrorLog::query()->resolved()->delete();

        return redirect()->route('admin.errors.index', ['status' => 'resolved'])
            ->with('status', trans_choice('{0} Nothing to clear.|{1} Deleted 1 resolved error.|[2,*] Deleted :count resolved errors.', $deleted, ['count' => $deleted]));
    }
}
