<?php

use App\Modules\Notifications\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->controller(NotificationController::class)->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('/read-all', 'markAllRead')->name('read-all');
    Route::post('/{notification}/read', 'markRead')->whereUuid('notification')->name('read');
});
