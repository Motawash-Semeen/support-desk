<?php

use App\Modules\Tickets\TicketController;
use Illuminate\Support\Facades\Route;

// {ticket} is the ticket number (bound in AppServiceProvider).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::controller(TicketController::class)->prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:tickets')->name('store');
        Route::get('/{ticket}', 'show')->name('show');
        Route::get('/{ticket}/edit', 'edit')->name('edit');
        Route::patch('/{ticket}', 'update')->name('update');
        Route::post('/{ticket}/status', 'changeStatus')->name('status');
    });

    Route::get('/staff/customers/search', [TicketController::class, 'customerSearch'])
        ->middleware('role:admin,agent')
        ->name('staff.customers.search');
});
