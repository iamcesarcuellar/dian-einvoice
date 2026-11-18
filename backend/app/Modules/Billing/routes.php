<?php

use App\Modules\Billing\Http\Controllers\NumberTemplateController;
use Illuminate\Support\Facades\Route;

// Tenant routes: they act on the company sent in X-Company-Id. Every member can read.
Route::middleware(['auth:sanctum', 'company'])->group(function (): void {
    Route::get('number-templates', [NumberTemplateController::class, 'index'])->name('number-templates.index');
    Route::get('number-templates/{numberTemplate}', [NumberTemplateController::class, 'show'])->name('number-templates.show');

    // Only admins and owners manage numbering.
    Route::middleware('permission:manage-settings')->group(function (): void {
        Route::post('number-templates', [NumberTemplateController::class, 'store'])->name('number-templates.store');
        Route::patch('number-templates/{numberTemplate}', [NumberTemplateController::class, 'update'])->name('number-templates.update');
        Route::delete('number-templates/{numberTemplate}', [NumberTemplateController::class, 'destroy'])->name('number-templates.destroy');
    });
});
