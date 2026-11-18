<?php

use App\Modules\Payments\Http\Controllers\OpenInvoicesController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\ReceivablesController;
use Illuminate\Support\Facades\Route;

// Tenant routes: they act on the company sent in X-Company-Id. Every member can read.
Route::middleware(['auth:sanctum', 'company'])->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('contacts/{contact}/open-invoices', OpenInvoicesController::class)->name('contacts.open-invoices');
    Route::get('receivables', ReceivablesController::class)->name('receivables');

    // Owners, admins and members record payments.
    Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:record-payments')->name('payments.store');

    // Only owners and admins void them.
    Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->middleware('permission:void-payments')->name('payments.void');
});
