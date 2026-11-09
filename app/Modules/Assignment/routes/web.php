<?php

use App\Modules\Assignment\Http\Controllers\AvailabilityController;
use App\Modules\Assignment\Http\Controllers\TicketAssignmentController;
use Illuminate\Support\Facades\Route;

// L5Modular wraps this file in the 'web' middleware group.
Route::middleware(['auth', 'verified'])->group(function () {
    // Compare-and-set writes; a lost race answers 409 Conflict.
    Route::controller(TicketAssignmentController::class)->prefix('tickets')->name('tickets.')->group(function () {
        Route::post('/{ticket}/accept', 'accept')->name('accept');
        Route::post('/{ticket}/assign', 'assign')->name('assign');
        Route::post('/{ticket}/release', 'release')->name('release');
    });

    Route::get('/staff/unassigned', [TicketAssignmentController::class, 'queue'])
        ->middleware('role:admin,agent')
        ->name('staff.unassigned');
});

Route::middleware(['auth', 'role:agent'])->group(function () {
    Route::patch('/profile/availability', AvailabilityController::class)->name('profile.availability.update');
});
