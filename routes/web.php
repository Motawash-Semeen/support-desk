<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Core routes only. Each module registers its own routes from
| app/Modules/{Module}/routes/web.php (L5Modular, inside the 'web' group).
|
| Authorization layers:
|  - 'auth' (and 'verified' for the support desk) on every module route.
|  - 'role:...' for coarse groups only (staff area, admin area).
|  - Every controller action calls $this->authorize() against a policy/gate.
|  - Ticket lists are additionally scoped with Ticket::visibleTo().
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
