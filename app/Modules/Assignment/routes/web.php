<?php

use App\Modules\Assignment\AssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::controller(AssignmentController::class)->prefix('tickets')->name('tickets.')->group(function () {
        Route::post('/{ticket}/accept', 'accept')->name('accept');
        Route::post('/{ticket}/assign', 'assign')->name('assign');
        Route::post('/{ticket}/release', 'release')->name('release');
    });

    Route::get('/staff/unassigned', [AssignmentController::class, 'queue'])
        ->middleware('role:admin,agent')
        ->name('staff.unassigned');
});

Route::middleware(['auth', 'role:agent'])->group(function () {
    Route::patch('/profile/availability', [AssignmentController::class, 'updateAvailability'])->name('profile.availability.update');
});
