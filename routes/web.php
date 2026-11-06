<?php

use App\Http\Controllers\Admin\SlaSettingsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Authorization layers:
|  - 'auth' on everything below.
|  - 'role:...' for coarse groups only (staff area, admin area).
|  - Every controller action calls $this->authorize() against a policy/gate.
|  - Ticket lists are additionally scoped with Ticket::visibleTo().
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');

    // Scoped: the attachment must belong to {ticket} or the route 404s.
    Route::get('/tickets/{ticket}/attachments/{attachment}', [AttachmentController::class, 'download'])
        ->scopeBindings()
        ->name('tickets.attachments.download');

    Route::middleware('role:admin,agent')->prefix('staff')->name('staff.')->group(function () {
        Route::get('/dashboard', StaffDashboardController::class)->name('dashboard');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/sla-settings', [SlaSettingsController::class, 'index'])->name('sla-settings.index');
        // Ticket import routes join this group when the import feature lands.
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
