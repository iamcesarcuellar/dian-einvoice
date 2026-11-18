<?php

use App\Http\ApiMiddleware;
use App\Modules\Catalog\Http\Controllers\ContactController;
use App\Modules\Catalog\Http\Controllers\ItemController;
use App\Modules\Catalog\Http\Controllers\TaxController;
use App\Modules\Catalog\Http\Controllers\WithholdingController;
use Illuminate\Support\Facades\Route;

// Tenant routes of the public API: the web app session (X-Company-Id) or an API key. Every member
// and every key can read.
Route::middleware(ApiMiddleware::TENANT)->group(function (): void {
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show');
    Route::get('taxes', [TaxController::class, 'index'])->name('taxes.index');
    Route::get('taxes/{tax}', [TaxController::class, 'show'])->name('taxes.show');
    Route::get('withholdings', [WithholdingController::class, 'index'])->name('withholdings.index');
    Route::get('withholdings/{withholding}', [WithholdingController::class, 'show'])->name('withholdings.show');

    // Members, admins, owners and read_write keys manage contacts and items.
    Route::middleware('permission:manage-catalog')->group(function (): void {
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
        Route::patch('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
        Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
        Route::post('items', [ItemController::class, 'store'])->name('items.store');
        Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    });

    // Only admins and owners manage taxes and withholdings, in the web app.
    Route::middleware('permission:manage-settings')->group(function (): void {
        Route::post('taxes', [TaxController::class, 'store'])->name('taxes.store');
        Route::patch('taxes/{tax}', [TaxController::class, 'update'])->name('taxes.update');
        Route::delete('taxes/{tax}', [TaxController::class, 'destroy'])->name('taxes.destroy');
        Route::post('withholdings', [WithholdingController::class, 'store'])->name('withholdings.store');
        Route::patch('withholdings/{withholding}', [WithholdingController::class, 'update'])->name('withholdings.update');
        Route::delete('withholdings/{withholding}', [WithholdingController::class, 'destroy'])->name('withholdings.destroy');
    });
});
