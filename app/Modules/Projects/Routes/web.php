<?php

declare(strict_types=1);

use App\Modules\Projects\Http\Controllers\ClientController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/**
 * M02 Projects & CRM. Every route is policy-checked in its controller (deny-by-default, CLAUDE.md rule 3).
 *
 * @see SRS M02-01
 */
Route::middleware(['web', 'app'])->prefix('projects')->name('projects.')->group(function (): void {
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
});
