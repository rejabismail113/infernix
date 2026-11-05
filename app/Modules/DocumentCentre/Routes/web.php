<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Modules\DocumentCentre\Http\Controllers\DocumentController;
use App\Modules\DocumentCentre\Http\Controllers\DocumentReportController;
use Illuminate\Support\Facades\Route;

/**
 * M06 Document Centre. Every route is authenticated (`app`) and policy-checked in the controller.
 * There is deliberately NO route that serves files by storage key or path.
 *
 * @see SRS M06-01 … M06-05, NFR-02, UAT-10
 */
Route::middleware(['web', 'app'])->prefix('documents')->name('documents.')->group(function (): void {
    Route::get('/', [DocumentController::class, 'index'])->name('index');
    Route::post('/', [DocumentController::class, 'store'])->middleware('throttle:30,1')->name('store');

    Route::get('link-targets', [DocumentController::class, 'linkTargets'])->middleware('throttle:60,1')->name('link-targets');
    Route::get('reports/inventory', [DocumentReportController::class, 'inventory'])->name('reports.inventory');
    Route::get('reports/inventory/export', [DocumentReportController::class, 'inventoryExport'])->name('reports.inventory.export');
    Route::get('reports/recent-changes', [DocumentReportController::class, 'recentChanges'])->name('reports.recent-changes');
    Route::get('reports/missing-evidence', [DocumentReportController::class, 'missingEvidence'])->name('reports.missing-evidence');

    // Signed, 5-minute, user-bound file stream (only reachable through download()).
    Route::get('stream/{version}', [DocumentController::class, 'stream'])->middleware(['signed', 'throttle:60,1'])->name('stream');

    Route::get('{document}', [DocumentController::class, 'show'])->whereNumber('document')->name('show');
    Route::get('{document}/download', [DocumentController::class, 'download'])->whereNumber('document')->middleware('throttle:60,1')->name('download');
    Route::post('{document}/versions', [DocumentController::class, 'storeVersion'])->whereNumber('document')->middleware('throttle:30,1')->name('versions.store');
    Route::post('{document}/links', [DocumentController::class, 'link'])->whereNumber('document')->name('links.store');
    Route::post('{document}/shares', [DocumentController::class, 'share'])->whereNumber('document')->name('shares.store');
    Route::delete('{document}/shares/{share}', [DocumentController::class, 'unshare'])->whereNumber('document')->name('shares.destroy');
    Route::post('{document}/archive', [DocumentController::class, 'archive'])->whereNumber('document')->name('archive');
    Route::post('{document}/legal-hold', [DocumentController::class, 'legalHold'])->whereNumber('document')->middleware(RequireRecentAuth::class)->name('legal-hold');
    Route::delete('{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->middleware(RequireRecentAuth::class)->name('destroy');
});
