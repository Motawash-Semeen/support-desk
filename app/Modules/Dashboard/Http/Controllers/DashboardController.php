<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Services\DashboardStatsService;
use App\Modules\Logs\Models\ErrorLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff dashboard. Admins see global numbers; agents see their own tickets
 * plus the unassigned queue. Numbers come from DashboardStatsService (a few
 * aggregate queries, cached 30 seconds). Admins also get the open-error count
 * from the error tracker.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStatsService $stats): View
    {
        $this->authorize('view-dashboard');

        $openErrors = null;
        if ($request->user()->can('view-error-log')) {
            $openErrors = ErrorLog::query()->open()->count();
            // The sidebar badge reads the same number instead of counting again.
            $request->attributes->set('open_errors', $openErrors);
        }

        return view('Dashboard::index', [
            'stats' => $stats->for($request->user()),
            'openErrors' => $openErrors,
        ]);
    }
}
