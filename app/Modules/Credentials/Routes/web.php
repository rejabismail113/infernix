<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Modules\Credentials\Http\Controllers\CredentialController;
use Illuminate\Support\Facades\Route;

/**
 * M13 Credentials & secrets. Policy-checked in the controller; the reveal needs recent re-authentication,
 * is throttled, audited and returns a no-store response (M13-03).
 *
 * @see SRS M13-01 … M13-06, WF-07
 */
Route::middleware(['web', 'app'])->prefix('credentials')->name('credentials.')->group(function (): void {
    Route::get('/', [CredentialController::class, 'index'])->name('credentials.index');
    Route::post('/', [CredentialController::class, 'store'])->name('credentials.store');
    Route::get('audit', [CredentialController::class, 'audit'])->name('audit');
    Route::get('jml', [CredentialController::class, 'jml'])->name('jml.index');
    Route::post('jml/{checklist}/tick', [CredentialController::class, 'tick'])->name('jml.tick');
    Route::get('{credential}', [CredentialController::class, 'show'])->whereNumber('credential')->name('credentials.show');
    Route::put('{credential}', [CredentialController::class, 'update'])->whereNumber('credential')->name('credentials.update');
    Route::post('{credential}/status', [CredentialController::class, 'status'])->name('credentials.status');
    Route::post('{credential}/rotate', [CredentialController::class, 'rotate'])->name('credentials.rotate');
    Route::post('{credential}/reveal', [CredentialController::class, 'reveal'])->middleware([RequireRecentAuth::class, 'throttle:10,1'])->name('credentials.reveal');
    Route::post('{credential}/request', [CredentialController::class, 'requestAccess'])->middleware('throttle:20,1')->name('credentials.request');
    Route::post('{credential}/grant', [CredentialController::class, 'grant'])->middleware(RequireRecentAuth::class)->name('credentials.grant');
    Route::post('requests/{accessRequest}/decide', [CredentialController::class, 'decide'])->middleware(RequireRecentAuth::class)->name('requests.decide');
    Route::post('entitlements/{entitlement}/revoke', [CredentialController::class, 'revoke'])->name('entitlements.revoke');
});
