<?php

use App\Modules\Dian\Http\Controllers\DianSettingsController;
use App\Modules\Dian\Http\Controllers\SigningCertificateController;
use Illuminate\Support\Facades\Route;

// Tenant routes: they act on the company sent in X-Company-Id. Only admins and owners.
Route::middleware(['auth:sanctum', 'company', 'permission:manage-settings'])->group(function (): void {
    Route::get('dian-settings', [DianSettingsController::class, 'show'])->name('dian-settings.show');
    Route::patch('dian-settings', [DianSettingsController::class, 'update'])->name('dian-settings.update');

    Route::get('dian-settings/certificate', [SigningCertificateController::class, 'show'])->name('dian-settings.certificate.show');
    Route::post('dian-settings/certificate', [SigningCertificateController::class, 'store'])->name('dian-settings.certificate.store');
    Route::delete('dian-settings/certificate', [SigningCertificateController::class, 'destroy'])->name('dian-settings.certificate.destroy');
    Route::post('dian-settings/certificate/test', [SigningCertificateController::class, 'generateTest'])->name('dian-settings.certificate.test');
});
