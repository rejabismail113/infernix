<?php

declare(strict_types=1);

use App\Modules\Assets\Http\Controllers\AssetController;
use App\Modules\Assets\Http\Controllers\DisposalController;
use Illuminate\Support\Facades\Route;

/** M05 Assets. Every action is policy-checked in the controller (deny-by-default). @see SRS M05-01 … M05-05 */
Route::middleware(['web', 'app'])->prefix('assets')->name('assets.')->group(function (): void {
    Route::get('/', [AssetController::class, 'index'])->name('assets.index');
    Route::post('/', [AssetController::class, 'store'])->name('assets.store');
    Route::post('candidates/{candidate}/register', [AssetController::class, 'register'])->name('candidates.register');
    Route::post('candidates/{candidate}/dismiss', [AssetController::class, 'dismiss'])->name('candidates.dismiss');
    Route::get('{asset}', [AssetController::class, 'show'])->whereNumber('asset')->name('assets.show');
    Route::put('{asset}', [AssetController::class, 'update'])->whereNumber('asset')->name('assets.update');
    Route::post('{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
    Route::post('{asset}/return', [AssetController::class, 'returnAsset'])->name('assets.return');
    Route::post('{asset}/maintenance', [AssetController::class, 'maintenance'])->name('assets.maintenance');
    Route::post('maintenance/{maintenance}/complete', [AssetController::class, 'completeMaintenance'])->name('maintenance.complete');
    Route::post('maintenance/{maintenance}/link', [AssetController::class, 'linkRepairCost'])->name('maintenance.link');
    Route::post('{asset}/disposals', [DisposalController::class, 'store'])->name('disposals.store');
    Route::post('disposals/{disposal}/submit', [DisposalController::class, 'submit'])->name('disposals.submit');
    Route::post('disposals/{disposal}/approve', [DisposalController::class, 'approve'])->name('disposals.approve');
    Route::post('disposals/{disposal}/reject', [DisposalController::class, 'reject'])->name('disposals.reject');
    Route::post('disposals/{disposal}/cancel', [DisposalController::class, 'cancel'])->name('disposals.cancel');
    Route::post('disposals/{disposal}/proceeds', [DisposalController::class, 'linkProceeds'])->name('disposals.proceeds');
});
