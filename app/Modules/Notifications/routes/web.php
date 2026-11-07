<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// L5Modular wraps this file in the 'web' middleware group.
// In-app notifications: always scoped to the signed-in user's own rows.
Route::middleware('auth')->controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/read-all', 'markAllRead')->name('read-all');
    Route::post('/{notification}/read', 'markRead')->whereUuid('notification')->name('read');
});
