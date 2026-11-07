<?php

use App\Modules\Logs\Http\Controllers\ErrorLogController;
use Illuminate\Support\Facades\Route;

// L5Modular wraps this file in the 'web' middleware group.
// Error tracker (ErrorRecorder feeds it). The raw log files are browsed with
// opcodesio/log-viewer at /admin/log-viewer (config/log-viewer.php).
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::controller(ErrorLogController::class)->prefix('admin/errors')->name('admin.errors.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/clear-resolved', 'clearResolved')->name('clear-resolved');
        Route::get('/{errorLog}', 'show')->name('show');
        Route::patch('/{errorLog}/resolve', 'resolve')->name('resolve');
        Route::delete('/{errorLog}', 'destroy')->name('destroy');
    });
});
