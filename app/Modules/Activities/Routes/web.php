<?php

declare(strict_types=1);

use App\Modules\Activities\Http\Controllers\ActivityController;
use Illuminate\Support\Facades\Route;

/** M07 Activities & travel. Policy-checked in the controller. @see SRS M07-01 … M07-03 */
Route::middleware(['web', 'app'])->prefix('activities')->name('activities.')->group(function (): void {
    Route::get('/', [ActivityController::class, 'index'])->name('activities.index');
    Route::post('/', [ActivityController::class, 'store'])->name('activities.store');
    Route::get('{activity}', [ActivityController::class, 'show'])->whereNumber('activity')->name('activities.show');
    Route::put('{activity}', [ActivityController::class, 'update'])->whereNumber('activity')->name('activities.update');
    Route::post('{activity}/status', [ActivityController::class, 'status'])->name('activities.status');
    Route::put('{activity}/attendees', [ActivityController::class, 'attendees'])->name('activities.attendees');
    Route::put('{activity}/itinerary', [ActivityController::class, 'itinerary'])->name('activities.itinerary');
    Route::put('{activity}/report', [ActivityController::class, 'report'])->name('activities.report');
    Route::get('{activity}/cost-candidates', [ActivityController::class, 'candidates'])->name('activities.cost-candidates');
    Route::post('{activity}/costs', [ActivityController::class, 'linkCost'])->name('activities.costs.link');
    Route::delete('{activity}/costs', [ActivityController::class, 'unlinkCost'])->name('activities.costs.unlink');
});
