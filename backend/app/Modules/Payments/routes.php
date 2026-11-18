<?php

use App\Http\ApiMiddleware;
use App\Modules\Payments\Http\Controllers\OpenInvoicesController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Payments\Http\Controllers\ReceivablesController;
use Illuminate\Support\Facades\Route;

// Tenant routes of the public API: the web app session (X-Company-Id) or an API key. Every member
// and every key can read.
Route::middleware(ApiMiddleware::TENANT)->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('contacts/{contact}/open-invoices', OpenInvoicesController::class)->name('contacts.open-invoices');
    Route::get('receivables', ReceivablesController::class)->name('receivables');

    // Owners, admins, members and read_write keys record payments.
    Route::post('payments', [PaymentController::class, 'store'])->middleware('permission:record-payments')->name('payments.store');

    // Only owners, admins and read_write keys void them.
    Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->middleware('permission:void-payments')->name('payments.void');
});
