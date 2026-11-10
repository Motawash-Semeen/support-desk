<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** After sign-in: staff go to the dashboard, customers to their tickets. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()->isStaff()
            ? redirect()->route('staff.dashboard')
            : redirect()->route('tickets.index');
    }
}
