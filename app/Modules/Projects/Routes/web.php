<?php

declare(strict_types=1);

use App\Modules\Projects\Http\Controllers\ClientController;
use App\Modules\Projects\Http\Controllers\LeadController;
use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\TrainingController;
use Illuminate\Support\Facades\Route;

/**
 * M02 Projects, CRM & Training. Every route is policy-checked in its controller (deny-by-default, CLAUDE.md rule 3).
 *
 * @see SRS M02-01 … M02-07
 */
Route::middleware(['web', 'app'])->prefix('projects')->name('projects.')->group(function (): void {
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');

    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
    Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::post('leads/{lead}/stage', [LeadController::class, 'stage'])->name('leads.stage');

    Route::get('/', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
    Route::put('{project}', [ProjectController::class, 'update'])->whereNumber('project')->name('projects.update');
    Route::post('{project}/status', [ProjectController::class, 'status'])->whereNumber('project')->name('projects.status');
    Route::post('{project}/closeout', [ProjectController::class, 'closeout'])->whereNumber('project')->name('projects.closeout');
    Route::post('{project}/members', [ProjectController::class, 'addMember'])->whereNumber('project')->name('members.store');
    Route::post('members/{member}/end', [ProjectController::class, 'endMember'])->name('members.end');
    Route::post('{project}/milestones', [ProjectController::class, 'storeMilestone'])->whereNumber('project')->name('milestones.store');
    Route::put('milestones/{milestone}', [ProjectController::class, 'updateMilestone'])->name('milestones.update');
    Route::post('milestones/{milestone}/tasks', [ProjectController::class, 'storeTask'])->name('tasks.store');
    Route::put('tasks/{task}', [ProjectController::class, 'updateTask'])->name('tasks.update');

    Route::post('{project}/sessions', [TrainingController::class, 'store'])->whereNumber('project')->name('sessions.store');
    Route::get('sessions/{session}', [TrainingController::class, 'show'])->name('sessions.show');
    Route::put('sessions/{session}', [TrainingController::class, 'update'])->name('sessions.update');
    Route::post('sessions/{session}/participants', [TrainingController::class, 'participants'])->name('sessions.participants');
    Route::post('sessions/{session}/certificates', [TrainingController::class, 'certificates'])->name('sessions.certificates');
});
