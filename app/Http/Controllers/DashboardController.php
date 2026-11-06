<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Post-login landing route: staff go to the staff dashboard, customers to
 * their ticket list.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return $request->user()->can('view-dashboard')
            ? redirect()->route('staff.dashboard')
            : redirect()->route('tickets.index');
    }
}
