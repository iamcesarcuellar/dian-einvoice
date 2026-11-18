<?php

use App\Modules\Dian\Http\Controllers\DianSettingsController;
use Illuminate\Support\Facades\Route;

// Tenant routes: they act on the company sent in X-Company-Id. Only admins and owners.
Route::middleware(['auth:sanctum', 'company', 'permission:manage-settings'])->group(function (): void {
    Route::get('dian-settings', [DianSettingsController::class, 'show'])->name('dian-settings.show');
    Route::patch('dian-settings', [DianSettingsController::class, 'update'])->name('dian-settings.update');
});
