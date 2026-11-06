<?php

declare(strict_types=1);

use App\Modules\Csr\Http\Controllers\CsrController;
use Illuminate\Support\Facades\Route;

/** M08 CSR. Policy-checked in the controller. @see SRS M08-01 … M08-04 */
Route::middleware(['web', 'app'])->prefix('csr')->name('csr.')->group(function (): void {
    Route::get('/', [CsrController::class, 'index'])->name('programmes.index');
    Route::post('/', [CsrController::class, 'store'])->name('programmes.store');
    Route::get('annual-report', [CsrController::class, 'annualReport'])->middleware('throttle:10,1')->name('annual-report');
    Route::get('{programme}', [CsrController::class, 'show'])->whereNumber('programme')->name('programmes.show');
    Route::put('{programme}', [CsrController::class, 'update'])->whereNumber('programme')->name('programmes.update');
    Route::put('{programme}/volunteers', [CsrController::class, 'volunteers'])->name('programmes.volunteers');
    Route::post('{programme}/in-kind', [CsrController::class, 'addInKind'])->name('programmes.in-kind');
    Route::delete('in-kind/{contribution}', [CsrController::class, 'removeInKind'])->name('in-kind.destroy');
    Route::post('{programme}/evidence', [CsrController::class, 'addEvidence'])->name('programmes.evidence');
    Route::post('evidence/{evidence}/consent', [CsrController::class, 'consent'])->name('evidence.consent');
    Route::post('evidence/{evidence}/publishable', [CsrController::class, 'publishable'])->name('evidence.publishable');
});
