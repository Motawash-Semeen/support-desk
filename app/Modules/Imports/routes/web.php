<?php

use App\Modules\Imports\TicketImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::controller(TicketImportController::class)->prefix('admin/imports')->name('admin.imports.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:imports')->name('store');
        Route::get('/sample', 'sample')->name('sample');
        Route::get('/{import}', 'show')->name('show');
        Route::get('/{import}/status', 'status')->name('status');
        Route::get('/{import}/errors', 'errors')->name('errors');
        Route::post('/{import}/resume', 'resume')->name('resume');
    });
});
