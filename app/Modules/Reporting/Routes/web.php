<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\CatalogReportController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\ExportController;
use App\Modules\Reporting\Http\Controllers\ProjectFinancialsController;
use App\Modules\Reporting\Http\Controllers\ReportIndexController;
use Illuminate\Support\Facades\Route;

/**
 * M11 Reporting. Dashboards and exports are permission-checked in their controllers (deny-by-default).
 *
 * @see SRS M11-01, M11-02, M11-05
 */
Route::middleware(['web', 'app'])->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('/', ReportIndexController::class)->name('index');
    Route::get('dashboard/owner', [DashboardController::class, 'owner'])->name('dashboard.owner');
    Route::get('dashboard/finance', [DashboardController::class, 'finance'])->name('dashboard.finance');
    Route::get('projects/{project}/financials', ProjectFinancialsController::class)->whereNumber('project')->name('projects.financials');
    Route::get('view/{key}', CatalogReportController::class)->where('key', '[a-z0-9.\-]+')->name('view');
    Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
    Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:20,1')->name('exports.store');
});
