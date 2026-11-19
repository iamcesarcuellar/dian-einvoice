<?php

use App\Http\ApiMiddleware;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\CompanyController;
use App\Modules\Identity\Http\Controllers\InvitationAcceptanceController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\ProfileController;
use App\Modules\Identity\Http\Controllers\TeamInvitationController;
use App\Modules\Identity\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

// Sign-up, sign-in and password recovery are public and limited per address (IP); see
// IdentityServiceProvider for the limits.
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register')->name('register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

    // A link by email that works once, for 60 minutes, and the new password.
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-email')->name('password.email');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset')->name('password.reset');

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
    Route::post('/', [CompanyController::class, 'store'])->middleware('throttle:companies')->name('store');
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
    Route::get('{token}', [InvitationAcceptanceController::class, 'show'])->middleware('throttle:invitation')->name('show');
    Route::post('{token}/accept', [InvitationAcceptanceController::class, 'accept'])->middleware('throttle:invitation-accept')->name('accept');
});
