<?php

use App\Http\ApiMiddleware;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\CompanyController;
use App\Modules\Identity\Http\Controllers\InvitationAcceptanceController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\TeamInvitationController;
use App\Modules\Identity\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');

        // The profile of the signed-in user: name and password.
        Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
        Route::put('password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('password.update');
    });
});

Route::middleware('auth:sanctum')->prefix('companies')->name('companies.')->group(function (): void {
    Route::get('/', [CompanyController::class, 'index'])->name('index');
    Route::post('/', [CompanyController::class, 'store'])->name('store');
    Route::get('{company}', [CompanyController::class, 'show'])->name('show');
    Route::patch('{company}', [CompanyController::class, 'update'])->name('update');
});

// The team of the current company: members, roles and invitations. Owners and admins, in the web
// app only (API keys get 403).
Route::middleware([...ApiMiddleware::PANEL, 'permission:manage-team'])->prefix('team')->name('team.')->group(function (): void {
    Route::get('members', [TeamMemberController::class, 'index'])->name('members.index');
    Route::patch('members/{membership}', [TeamMemberController::class, 'update'])->name('members.update');
    Route::delete('members/{membership}', [TeamMemberController::class, 'destroy'])->name('members.destroy');

    Route::get('invitations', [TeamInvitationController::class, 'index'])->name('invitations.index');
    Route::post('invitations', [TeamInvitationController::class, 'store'])->name('invitations.store');
    Route::post('invitations/{invitation}/resend', [TeamInvitationController::class, 'resend'])->name('invitations.resend');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('invitations.destroy');
});

// The page of an invitation link, public and without a company: the token is the credential.
Route::prefix('invitations')->name('invitations.')->group(function (): void {
    Route::get('{token}', [InvitationAcceptanceController::class, 'show'])->middleware('throttle:30,1')->name('show');
    Route::post('{token}/accept', [InvitationAcceptanceController::class, 'accept'])->middleware('throttle:10,1')->name('accept');
});
