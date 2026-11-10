<?php

namespace App\Modules\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Staff dashboard: global numbers for admins; own tickets plus the queue for agents. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $stats): View
    {
        return view('Dashboard::index', [
            'stats' => $stats->for($request->user()),
        ]);
    }
}
