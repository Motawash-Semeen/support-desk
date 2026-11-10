<?php

use App\Modules\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin,agent'])->group(function () {
    Route::get('/staff/dashboard', DashboardController::class)->name('staff.dashboard');
});
