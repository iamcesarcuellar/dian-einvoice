<?php

use App\Http\ApiMiddleware;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Delivery\Http\Controllers\DeliveryController;
use App\Modules\Delivery\Http\Controllers\LocalMailboxController;
use Illuminate\Support\Facades\Route;

// Tenant routes of the public API: the web app session (X-Company-Id) or an API key. Every member
// and every key can read and download.
Route::middleware(ApiMiddleware::TENANT)->group(function (): void {
    // Invoices, credit notes and debit notes are delivered the same way.
    foreach (DocumentType::cases() as $type) {
        $path = $type->path();

        Route::get("{$path}/{document}/pdf", [DeliveryController::class, 'pdf'])->name("{$path}.pdf");
        Route::get("{$path}/{document}/attached-document", [DeliveryController::class, 'attachedDocument'])->name("{$path}.attached-document");
        Route::get("{$path}/{document}/package", [DeliveryController::class, 'package'])->name("{$path}.package");

        // Members, admins, owners and read_write keys send again and regenerate.
        Route::middleware('permission:manage-documents')->group(function () use ($path): void {
            Route::post("{$path}/{document}/deliver", [DeliveryController::class, 'deliver'])->name("{$path}.deliver");
            Route::post("{$path}/{document}/pdf/regenerate", [DeliveryController::class, 'regeneratePdf'])->name("{$path}.pdf.regenerate");
        });
    }
});

// The local development mailbox, next to the simulator in Settings → DIAN: owners and admins, in the web app.
Route::middleware([...ApiMiddleware::PANEL, 'permission:manage-settings'])->group(function (): void {
    Route::get('local-mailbox', LocalMailboxController::class)->name('local-mailbox');
});
