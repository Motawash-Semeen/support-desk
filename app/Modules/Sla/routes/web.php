<?php

use App\Modules\Sla\SlaSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::controller(SlaSettingsController::class)->prefix('admin/sla-settings')->name('admin.sla-settings.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::put('/policies', 'updatePolicies')->name('policies.update');
        Route::put('/business-hours', 'updateBusinessHours')->name('business-hours.update');
        Route::post('/holidays', 'storeHoliday')->name('holidays.store');
        Route::delete('/holidays/{holiday}', 'destroyHoliday')->name('holidays.destroy');
    });
});
