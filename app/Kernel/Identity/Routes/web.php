<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Controllers\AuthenticatedSessionController;
use App\Kernel\Identity\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');
    });

    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');
    });
});
