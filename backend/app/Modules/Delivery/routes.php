<?php

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Delivery\Http\Controllers\DeliveryController;
use App\Modules\Delivery\Http\Controllers\LocalMailboxController;
use Illuminate\Support\Facades\Route;

// Tenant routes: they act on the company sent in X-Company-Id. Every member can read and download.
Route::middleware(['auth:sanctum', 'company'])->group(function (): void {
    // Invoices, credit notes and debit notes are delivered the same way.
    foreach (DocumentType::cases() as $type) {
        $path = $type->path();

        Route::get("{$path}/{document}/pdf", [DeliveryController::class, 'pdf'])->name("{$path}.pdf");
        Route::get("{$path}/{document}/attached-document", [DeliveryController::class, 'attachedDocument'])->name("{$path}.attached-document");
        Route::get("{$path}/{document}/package", [DeliveryController::class, 'package'])->name("{$path}.package");

        // Members, admins and owners send again and regenerate.
        Route::middleware('permission:manage-documents')->group(function () use ($path): void {
            Route::post("{$path}/{document}/deliver", [DeliveryController::class, 'deliver'])->name("{$path}.deliver");
            Route::post("{$path}/{document}/pdf/regenerate", [DeliveryController::class, 'regeneratePdf'])->name("{$path}.pdf.regenerate");
        });
    }

    // The local development mailbox, next to the simulator in Settings → DIAN: owners and admins.
    Route::get('local-mailbox', LocalMailboxController::class)->middleware('permission:manage-settings')->name('local-mailbox');
});
