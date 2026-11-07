<?php

use App\Http\Controllers\Admin\SlaSettingsController;
use App\Http\Controllers\Admin\TicketImportController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Staff\CustomerSearchController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\TicketAssignmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketReplyController;
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

    // {ticket} resolves by ticket_number through visibleTo() (see AppServiceProvider).
    Route::controller(TicketController::class)->prefix('tickets')->name('tickets.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:tickets')->name('store');
        Route::get('/{ticket}', 'show')->name('show');
        Route::get('/{ticket}/edit', 'edit')->name('edit');
        Route::patch('/{ticket}', 'update')->name('update');
        Route::post('/{ticket}/resolve', 'resolve')->name('resolve');
        Route::post('/{ticket}/close', 'close')->name('close');
        Route::post('/{ticket}/reopen', 'reopen')->name('reopen');
        Route::post('/{ticket}/status', 'changeStatus')->name('status');

        // Assignment: compare-and-set writes; a lost race answers 409 Conflict.
        Route::post('/{ticket}/accept', [TicketAssignmentController::class, 'accept'])->name('accept');
        Route::post('/{ticket}/assign', [TicketAssignmentController::class, 'assign'])->name('assign');
        Route::post('/{ticket}/release', [TicketAssignmentController::class, 'release'])->name('release');

        // Conversation: the reply type is decided on the server (StoreReplyRequest::replyType).
        Route::post('/{ticket}/replies', [TicketReplyController::class, 'store'])
            ->middleware('throttle:replies')
            ->name('replies.store');
    });

    // Files are streamed only through AttachmentPolicy; denied or unknown ids are 404.
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->middleware('throttle:downloads')
        ->name('attachments.download');

    Route::middleware('role:admin,agent')->prefix('staff')->name('staff.')->group(function () {
        Route::get('/dashboard', StaffDashboardController::class)->name('dashboard');
        Route::get('/unassigned', [TicketAssignmentController::class, 'queue'])->name('unassigned');
        Route::get('/customers/search', CustomerSearchController::class)->name('customers.search');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::controller(SlaSettingsController::class)->prefix('sla-settings')->name('sla-settings.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::put('/policies', 'updatePolicies')->name('policies.update');
            Route::put('/business-hours', 'updateBusinessHours')->name('business-hours.update');
            Route::post('/holidays', 'storeHoliday')->name('holidays.store');
            Route::delete('/holidays/{holiday}', 'destroyHoliday')->name('holidays.destroy');
        });

        // CSV ticket import (queued; status page polls the JSON endpoint).
        Route::controller(TicketImportController::class)->prefix('imports')->name('imports.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('throttle:imports')->name('store');
            Route::get('/sample', 'sample')->name('sample');
            Route::get('/{import}', 'show')->name('show');
            Route::get('/{import}/status', 'status')->name('status');
            Route::get('/{import}/errors', 'errors')->name('errors');
            Route::post('/{import}/resume', 'resume')->name('resume');
        });
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::patch('/profile/availability', AvailabilityController::class)->name('profile.availability.update');

    // In-app notifications: always scoped to the signed-in user's own rows.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereUuid('notification')
        ->name('notifications.read');
});

require __DIR__.'/auth.php';
