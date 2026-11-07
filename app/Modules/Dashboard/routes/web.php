<?php

use App\Modules\Dashboard\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// L5Modular wraps this file in the 'web' middleware group.
Route::middleware(['auth', 'verified', 'role:admin,agent'])->group(function () {
    Route::get('/staff/dashboard', DashboardController::class)->name('staff.dashboard');
});
