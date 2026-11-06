<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Minimal staff landing page. Each count is a single indexed COUNT query
 * built from the same scopes the scanners use.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $this->authorize('view-dashboard');

        $user = $request->user();

        $stats = [
            'active' => Ticket::query()->active()->count(),
            'unassigned' => Ticket::query()->active()->unassigned()->count(),
            'approaching' => Ticket::query()->slaApproaching()->count(),
            'breached' => Ticket::query()->slaBreached()->count(),
            'mine' => Ticket::query()->active()->assignedTo($user)->count(),
        ];

        $breached = Ticket::query()
            ->slaBreached()
            ->selectListColumns()
            ->withListRelations()
            ->orderBy('due_at')
            ->limit(10)
            ->get();

        return view('staff.dashboard', ['stats' => $stats, 'breached' => $breached]);
    }
}
