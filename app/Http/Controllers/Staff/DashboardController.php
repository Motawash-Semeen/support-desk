<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff dashboard. Admins see global numbers; agents see their own tickets
 * plus the unassigned queue. Numbers come from DashboardStatsService (a few
 * aggregate queries, cached 30 seconds).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStatsService $stats): View
    {
        $this->authorize('view-dashboard');

        return view('staff.dashboard', ['stats' => $stats->for($request->user())]);
    }
}
