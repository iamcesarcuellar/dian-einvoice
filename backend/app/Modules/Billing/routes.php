<?php

use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\NumberTemplateController;
use Illuminate\Support\Facades\Route;

// Tenant routes: they act on the company sent in X-Company-Id. Every member can read.
Route::middleware(['auth:sanctum', 'company'])->group(function (): void {
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('number-templates', [NumberTemplateController::class, 'index'])->name('number-templates.index');
    Route::get('number-templates/{numberTemplate}', [NumberTemplateController::class, 'show'])->name('number-templates.show');

    // Members, admins and owners write drafts and issue them.
    Route::middleware('permission:manage-documents')->group(function (): void {
        Route::post('invoices/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    });

    // Only admins and owners manage numbering.
    Route::middleware('permission:manage-settings')->group(function (): void {
        Route::post('number-templates', [NumberTemplateController::class, 'store'])->name('number-templates.store');
        Route::patch('number-templates/{numberTemplate}', [NumberTemplateController::class, 'update'])->name('number-templates.update');
        Route::delete('number-templates/{numberTemplate}', [NumberTemplateController::class, 'destroy'])->name('number-templates.destroy');
    });
});
