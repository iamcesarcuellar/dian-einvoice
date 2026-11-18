<?php

use App\Modules\Delivery\Http\Controllers\DeliveryController;
use App\Modules\Delivery\Http\Controllers\LocalMailboxController;
use Illuminate\Support\Facades\Route;

// Tenant routes: they act on the company sent in X-Company-Id. Every member can read and download.
Route::middleware(['auth:sanctum', 'company'])->group(function (): void {
    Route::get('invoices/{invoice}/pdf', [DeliveryController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/attached-document', [DeliveryController::class, 'attachedDocument'])->name('invoices.attached-document');
    Route::get('invoices/{invoice}/package', [DeliveryController::class, 'package'])->name('invoices.package');

    // Members, admins and owners send again and regenerate.
    Route::middleware('permission:manage-documents')->group(function (): void {
        Route::post('invoices/{invoice}/deliver', [DeliveryController::class, 'deliver'])->name('invoices.deliver');
        Route::post('invoices/{invoice}/pdf/regenerate', [DeliveryController::class, 'regeneratePdf'])->name('invoices.pdf.regenerate');
    });

    // The local development mailbox, next to the simulator in Settings → DIAN: owners and admins.
    Route::get('local-mailbox', LocalMailboxController::class)->middleware('permission:manage-settings')->name('local-mailbox');
});
