<?php

namespace App\Http\Controllers;

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
        return $request->user()->isStaff()
            ? redirect()->route('staff.dashboard')
            : redirect()->route('tickets.index');
    }
}
