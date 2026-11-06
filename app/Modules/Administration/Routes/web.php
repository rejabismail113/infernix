<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Modules\Administration\Http\Controllers\AccessReportController;
use App\Modules\Administration\Http\Controllers\ApprovalMatrixController;
use App\Modules\Administration\Http\Controllers\AuditLogController;
use App\Modules\Administration\Http\Controllers\BackupController;
use App\Modules\Administration\Http\Controllers\CompanyController;
use App\Modules\Administration\Http\Controllers\DataImportController;
use App\Modules\Administration\Http\Controllers\DelegationController;
use App\Modules\Administration\Http\Controllers\DictionaryController;
use App\Modules\Administration\Http\Controllers\FiscalPeriodController;
use App\Modules\Administration\Http\Controllers\NotificationRuleController;
use App\Modules\Administration\Http\Controllers\NumberingController;
use App\Modules\Administration\Http\Controllers\PermissionController;
use App\Modules\Administration\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/**
 * M12 Administration. Every action authorizes via a Policy (deny-by-default, CLAUDE.md rule 3);
 * sensitive mutations additionally require recent re-authentication.
 *
 * @see SRS M12-01 … M12-05, G-01 … G-06, G-09
 */
Route::middleware(['web', 'app'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users/invite', [UserController::class, 'invite'])->middleware(RequireRecentAuth::class)->name('users.invite');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('users/{user}/roles', [UserController::class, 'roles'])->middleware(RequireRecentAuth::class)->name('users.roles');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->middleware(RequireRecentAuth::class)->name('users.deactivate');
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->middleware(RequireRecentAuth::class)->name('users.reactivate');

    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
    Route::put('permissions', [PermissionController::class, 'update'])->middleware(RequireRecentAuth::class)->name('permissions.update');

    Route::get('approval-matrix', [ApprovalMatrixController::class, 'index'])->name('approval-matrix.index');
    Route::post('approval-matrix', [ApprovalMatrixController::class, 'store'])->middleware(RequireRecentAuth::class)->name('approval-matrix.store');

    Route::get('delegations', [DelegationController::class, 'index'])->name('delegations.index');
    Route::post('delegations', [DelegationController::class, 'store'])->name('delegations.store');
    Route::post('delegations/{delegation}/revise', [DelegationController::class, 'revise'])->name('delegations.revise');
    Route::post('delegations/{delegation}/revoke', [DelegationController::class, 'revoke'])->name('delegations.revoke');

    Route::get('company', [CompanyController::class, 'edit'])->name('company.edit');
    Route::put('company', [CompanyController::class, 'update'])->name('company.update');
    Route::post('company/bank-accounts', [CompanyController::class, 'storeBankAccount'])->middleware(RequireRecentAuth::class)->name('company.bank-accounts.store');

    Route::get('fiscal-periods', [FiscalPeriodController::class, 'index'])->name('fiscal-periods.index');
    Route::post('fiscal-years', [FiscalPeriodController::class, 'storeYear'])->name('fiscal-years.store');
    Route::post('fiscal-periods/{period}/lock', [FiscalPeriodController::class, 'lock'])->middleware(RequireRecentAuth::class.':mfa')->name('fiscal-periods.lock');
    Route::post('fiscal-periods/{period}/unlock', [FiscalPeriodController::class, 'unlock'])->middleware(RequireRecentAuth::class.':mfa')->name('fiscal-periods.unlock');

    Route::get('numbering', [NumberingController::class, 'index'])->name('numbering.index');
    Route::post('numbering', [NumberingController::class, 'store'])->name('numbering.store');
    Route::put('numbering/{sequence}', [NumberingController::class, 'update'])->name('numbering.update');

    Route::get('dictionaries', [DictionaryController::class, 'index'])->name('dictionaries.index');
    Route::post('dictionaries/{dictionary}/items', [DictionaryController::class, 'storeItem'])->name('dictionaries.items.store');
    Route::put('dictionary-items/{item}', [DictionaryController::class, 'updateItem'])->name('dictionaries.items.update');
    Route::post('claim-types/{claimType}/rates', [DictionaryController::class, 'storeRate'])->name('claim-types.rates.store');

    Route::get('notification-rules', [NotificationRuleController::class, 'index'])->name('notification-rules.index');
    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::get('imports', [DataImportController::class, 'index'])->name('imports.index');
    Route::get('imports/template/{type}', [DataImportController::class, 'template'])->where('type', '[a-z_]+')->name('imports.template');
    Route::post('imports', [DataImportController::class, 'run'])->middleware([RequireRecentAuth::class, 'throttle:10,1'])->name('imports.run');
    Route::post('backups', [BackupController::class, 'run'])->middleware([RequireRecentAuth::class, 'throttle:5,1'])->name('backups.run');
    Route::post('notification-rules', [NotificationRuleController::class, 'store'])->name('notification-rules.store');
    Route::put('notification-rules/{rule}', [NotificationRuleController::class, 'update'])->name('notification-rules.update');

    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
    Route::get('audit-log/export', [AuditLogController::class, 'export'])->name('audit-log.export');

    Route::get('reports/user-access', [AccessReportController::class, 'userAccess'])->name('reports.user-access');
    Route::get('reports/user-access/export', [AccessReportController::class, 'userAccessExport'])->name('reports.user-access.export');
    Route::get('reports/policy-history', [AccessReportController::class, 'policyHistory'])->name('reports.policy-history');
    Route::get('reports/policy-history/export', [AccessReportController::class, 'policyHistoryExport'])->name('reports.policy-history.export');
});
