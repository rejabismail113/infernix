<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Controllers\AuthenticatedSessionController;
use App\Kernel\Identity\Http\Controllers\DashboardController;
use App\Kernel\Identity\Http\Controllers\InvitationAcceptController;
use App\Kernel\Identity\Http\Controllers\MfaController;
use App\Kernel\Identity\Http\Controllers\PasswordResetController;
use App\Kernel\Identity\Http\Controllers\ReauthController;
use App\Kernel\Notifications\Http\NotificationController;
use App\Kernel\Search\Http\SearchController;
use Illuminate\Support\Facades\Route;

/** @see SRS G-01 */
Route::middleware('web')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/invitations/{token}', [InvitationAcceptController::class, 'show'])->name('invitations.accept');
        Route::post('/invitations/{token}', [InvitationAcceptController::class, 'store'])->middleware('throttle:10,1')->name('invitations.store');
        Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
        Route::get('/reset-password/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
    });

    // Authenticated but BEFORE the MFA gate (enrolment/challenge pages themselves).
    Route::middleware(['auth', 'active'])->group(function (): void {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
        Route::post('/mfa/setup', [MfaController::class, 'confirm'])->middleware('throttle:10,1')->name('mfa.confirm');
        Route::get('/mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
        Route::post('/mfa/challenge', [MfaController::class, 'verify'])->middleware('throttle:10,1')->name('mfa.verify');
    });

    Route::middleware(['app'])->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/reauth', [ReauthController::class, 'show'])->name('reauth.show');
        Route::post('/reauth', [ReauthController::class, 'store'])->middleware('throttle:10,1')->name('reauth.store');
        Route::get('/search', SearchController::class)->middleware('throttle:60,1')->name('search');
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/{notification}/open', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    });
});
