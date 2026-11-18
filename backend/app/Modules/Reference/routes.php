<?php

use App\Http\ApiMiddleware;
use App\Modules\Reference\Http\Controllers\ReferenceController;
use Illuminate\Support\Facades\Route;

// Code lists of the DIAN and the municipalities, for the web app session and for API keys.
Route::middleware(ApiMiddleware::SIGNED_IN)->prefix('reference')->name('reference.')->group(function (): void {
    Route::get('/', [ReferenceController::class, 'index'])->name('index');
    Route::get('municipalities', [ReferenceController::class, 'municipalities'])->name('municipalities');
});
