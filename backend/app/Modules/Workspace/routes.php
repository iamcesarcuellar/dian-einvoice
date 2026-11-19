<?php

use App\Http\ApiMiddleware;
use App\Modules\Workspace\Http\Controllers\DashboardController;
use App\Modules\Workspace\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Screens of the web app that look across modules, on the company sent in X-Company-Id. Web app
// session only (API keys get 403); every role, viewers included, reads them.
Route::middleware(ApiMiddleware::PANEL)->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('search', SearchController::class)->name('search');
});
