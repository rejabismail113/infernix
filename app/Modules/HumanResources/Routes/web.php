<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Modules\HumanResources\Http\Controllers\MemoController;
use App\Modules\HumanResources\Http\Controllers\PaymentBatchController;
use App\Modules\HumanResources\Http\Controllers\StaffController;
use App\Modules\HumanResources\Http\Controllers\TimesheetController;
use Illuminate\Support\Facades\Route;

/**
 * M04 HR & part-time staff. Every action is policy-checked in its controller (deny-by-default); reveals of
 * identifiers / bank details and the full payroll handoff need recent re-authentication and are audited.
 *
 * @see SRS M04-01 … M04-06
 */
Route::middleware(['web', 'app'])->prefix('hr')->name('hr.')->group(function (): void {
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
    Route::put('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('staff/{staff}/reveal-id', [StaffController::class, 'revealId'])->middleware([RequireRecentAuth::class, 'throttle:20,1'])->name('staff.reveal-id');
    Route::post('staff/{staff}/reveal-bank', [StaffController::class, 'revealBank'])->middleware([RequireRecentAuth::class, 'throttle:20,1'])->name('staff.reveal-bank');
    Route::put('staff/{staff}/bank', [StaffController::class, 'updateBank'])->middleware(RequireRecentAuth::class)->name('staff.bank');
    Route::post('staff/{staff}/engagements', [StaffController::class, 'storeEngagement'])->name('staff.engagements.store');

    Route::get('timesheets', [TimesheetController::class, 'index'])->name('timesheets.index');
    Route::post('timesheets', [TimesheetController::class, 'store'])->name('timesheets.store');
    Route::get('timesheets/{timesheet}', [TimesheetController::class, 'show'])->name('timesheets.show');
    Route::put('timesheets/{timesheet}/entries', [TimesheetController::class, 'entries'])->name('timesheets.entries');
    Route::post('timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->name('timesheets.submit');
    Route::post('timesheets/{timesheet}/verify', [TimesheetController::class, 'verify'])->name('timesheets.verify');
    Route::post('timesheets/{timesheet}/calculate', [TimesheetController::class, 'calculate'])->name('timesheets.calculate');

    Route::get('batches', [PaymentBatchController::class, 'index'])->name('batches.index');
    Route::post('batches', [PaymentBatchController::class, 'store'])->name('batches.store');
    Route::get('batches/{batch}', [PaymentBatchController::class, 'show'])->name('batches.show');
    Route::post('batches/{batch}/submit', [PaymentBatchController::class, 'submit'])->name('batches.submit');
    Route::post('batches/{batch}/approve', [PaymentBatchController::class, 'approve'])->name('batches.approve');
    Route::post('batches/{batch}/cancel', [PaymentBatchController::class, 'cancel'])->name('batches.cancel');
    Route::post('batches/{batch}/pay', [PaymentBatchController::class, 'pay'])->middleware(['idempotent', RequireRecentAuth::class])->name('batches.pay');
    Route::get('batches/{batch}/handoff', [PaymentBatchController::class, 'handoff'])->middleware(RequireRecentAuth::class.':mfa')->name('batches.handoff');

    Route::get('memos', [MemoController::class, 'index'])->name('memos.index');
    Route::post('memos/{memo}/read', [MemoController::class, 'read'])->name('memos.read');
});
