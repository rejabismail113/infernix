<?php

declare(strict_types=1);

use App\Modules\Procurement\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

/** M10 (minimal in P1): vendor directory. @see SRS M10-01 */
Route::middleware(['web', 'app'])->prefix('procurement')->name('procurement.')->group(function (): void {
    Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
    Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
});
