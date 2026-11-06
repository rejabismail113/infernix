<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Modules\Finance\Http\Controllers\AccountController;
use App\Modules\Finance\Http\Controllers\BankController;
use App\Modules\Finance\Http\Controllers\BankSettingsController;
use App\Modules\Finance\Http\Controllers\BillController;
use App\Modules\Finance\Http\Controllers\ClaimController;
use App\Modules\Finance\Http\Controllers\FinanceRecordController;
use App\Modules\Finance\Http\Controllers\JournalController;
use App\Modules\Finance\Http\Controllers\OverviewController;
use App\Modules\Finance\Http\Controllers\PaymentController;
use App\Modules\Finance\Http\Controllers\PeriodCloseController;
use App\Modules\Finance\Http\Controllers\ReportController;
use App\Modules\Finance\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

/**
 * M01 Finance. Every action is policy-checked in its controller (deny-by-default). Payment creation and
 * release are idempotent (§5.1); reversals and releases need recent re-authentication.
 *
 * @see SRS M01-01 … M01-10
 */
Route::middleware(['web', 'app'])->prefix('finance')->name('finance.')->group(function (): void {
    Route::get('/', OverviewController::class)->name('overview');

    Route::get('records/search', [FinanceRecordController::class, 'search'])->middleware('throttle:120,1')->name('records.search');
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');

    Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
    Route::get('journals/create', [JournalController::class, 'create'])->name('journals.create');
    Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
    Route::post('journals/{journal}/approve', [JournalController::class, 'approve'])->name('journals.approve');
    Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->middleware(RequireRecentAuth::class)->name('journals.reverse');
    Route::delete('journals/{journal}', [JournalController::class, 'destroy'])->name('journals.destroy');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::post('transactions/{transaction}/post', [TransactionController::class, 'post'])->name('transactions.post');
    Route::post('transactions/{transaction}/reverse', [TransactionController::class, 'reverse'])->middleware(RequireRecentAuth::class)->name('transactions.reverse');
    Route::post('transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
    Route::put('transactions/{transaction}/tax-treatment', [TransactionController::class, 'taxTreatment'])->name('transactions.tax-treatment');

    Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
    Route::get('claims/create', [ClaimController::class, 'create'])->name('claims.create');
    Route::post('claims', [ClaimController::class, 'store'])->name('claims.store');
    Route::get('claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');
    Route::get('claims/{claim}/edit', [ClaimController::class, 'edit'])->name('claims.edit');
    Route::put('claims/{claim}', [ClaimController::class, 'update'])->name('claims.update');
    Route::post('claims/{claim}/submit', [ClaimController::class, 'submit'])->name('claims.submit');
    Route::post('claims/{claim}/approve', [ClaimController::class, 'approve'])->name('claims.approve');
    Route::post('claims/{claim}/reject', [ClaimController::class, 'reject'])->name('claims.reject');
    Route::post('claims/{claim}/close', [ClaimController::class, 'close'])->name('claims.close');
    Route::post('claims/{claim}/cancel', [ClaimController::class, 'cancel'])->name('claims.cancel');

    Route::get('bills', [BillController::class, 'index'])->name('bills.index');
    Route::get('bills/create', [BillController::class, 'create'])->name('bills.create');
    Route::post('bills', [BillController::class, 'store'])->name('bills.store');
    Route::get('bills/{bill}', [BillController::class, 'show'])->name('bills.show');
    Route::post('bills/{bill}/submit', [BillController::class, 'submit'])->name('bills.submit');
    Route::post('bills/{bill}/approve', [BillController::class, 'approve'])->name('bills.approve');
    Route::post('bills/{bill}/return', [BillController::class, 'returnToDraft'])->name('bills.return');
    Route::post('bills/{bill}/cancel', [BillController::class, 'cancel'])->name('bills.cancel');
    Route::put('bills/{bill}/tax-treatment', [BillController::class, 'taxTreatment'])->name('bills.tax-treatment');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('idempotent')->name('payments.store');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('payments/{payment}/submit', [PaymentController::class, 'submit'])->name('payments.submit');
    Route::post('payments/{payment}/approve', [PaymentController::class, 'approve'])->name('payments.approve');
    Route::post('payments/{payment}/post', [PaymentController::class, 'post'])->middleware(['idempotent', RequireRecentAuth::class])->name('payments.post');
    Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');

    Route::get('bank', [BankController::class, 'index'])->name('bank.index');
    Route::get('bank/settings', [BankSettingsController::class, 'index'])->name('bank.settings');
    Route::post('bank/profiles', [BankSettingsController::class, 'saveProfile'])->name('bank.profiles.store');
    Route::put('bank/profiles/{profile}', [BankSettingsController::class, 'saveProfile'])->name('bank.profiles.update');
    Route::post('bank/rules', [BankSettingsController::class, 'saveRule'])->name('bank.rules.store');
    Route::put('bank/rules/{rule}', [BankSettingsController::class, 'saveRule'])->name('bank.rules.update');
    Route::get('bank/{bank}', [BankController::class, 'show'])->whereNumber('bank')->name('bank.show');
    Route::post('bank/{bank}/auto-match', [BankController::class, 'autoMatch'])->middleware('throttle:20,1')->name('bank.auto-match');
    Route::post('bank/{bank}/match-group', [BankController::class, 'matchGroup'])->name('bank.match-group');
    Route::get('bank/{bank}/ledger-lines', [BankController::class, 'ledgerLines'])->name('bank.ledger-lines');
    Route::post('bank/{bank}/import', [BankController::class, 'import'])->middleware('throttle:20,1')->name('bank.import');
    Route::post('bank/{bank}/transactions', [BankController::class, 'storeTransaction'])->name('bank.transactions.store');
    Route::get('bank/{bank}/reconciliation', [BankController::class, 'reconciliation'])->name('bank.reconciliation');
    Route::post('bank-transactions/{transaction}/match', [BankController::class, 'match'])->name('bank.match');
    Route::post('bank-transactions/{transaction}/unmatch', [BankController::class, 'unmatch'])->name('bank.unmatch');

    Route::get('periods', [PeriodCloseController::class, 'index'])->name('periods.index');
    Route::get('periods/{period}', [PeriodCloseController::class, 'show'])->name('periods.show');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/tax-pack', [ReportController::class, 'taxPack'])->name('reports.tax-pack');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
});
