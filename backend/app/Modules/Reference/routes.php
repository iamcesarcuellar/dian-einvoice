<?php

use App\Modules\Reference\Http\Controllers\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('reference')->name('reference.')->group(function (): void {
    Route::get('/', [ReferenceController::class, 'index'])->name('index');
    Route::get('municipalities', [ReferenceController::class, 'municipalities'])->name('municipalities');
});
