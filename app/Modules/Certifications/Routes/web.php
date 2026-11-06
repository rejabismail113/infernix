<?php

declare(strict_types=1);

use App\Modules\Certifications\Http\Controllers\CertificationController;
use Illuminate\Support\Facades\Route;

/** M09 Certifications & memberships. Policy-checked in the controller. @see SRS M09-01 … M09-04 */
Route::middleware(['web', 'app'])->prefix('certifications')->name('certifications.')->group(function (): void {
    Route::get('/', [CertificationController::class, 'index'])->name('certifications.index');
    Route::post('/', [CertificationController::class, 'storeCertification'])->name('certifications.store');
    Route::put('{certification}', [CertificationController::class, 'updateCertification'])->whereNumber('certification')->name('certifications.update');
    Route::post('memberships', [CertificationController::class, 'storeMembership'])->name('memberships.store');
    Route::put('memberships/{membership}', [CertificationController::class, 'updateMembership'])->name('memberships.update');
    Route::post('renewals/{renewal}/renew', [CertificationController::class, 'renew'])->name('renewals.renew');
    Route::post('renewals/{renewal}/lapse', [CertificationController::class, 'lapse'])->name('renewals.lapse');
    Route::post('renewals/{renewal}/link', [CertificationController::class, 'linkExpense'])->name('renewals.link');
});
