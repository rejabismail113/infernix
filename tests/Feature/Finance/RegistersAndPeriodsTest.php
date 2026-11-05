<?php

declare(strict_types=1);

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Money\Money;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Services\FiscalPeriodService;
use App\Modules\Finance\Enums\TaxTreatment;
use App\Modules\Finance\Events\AssetQualifyingPurchaseRecorded;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Posting\JournalDraft;
use App\Modules\Finance\Posting\JournalLineDraft;
use App\Modules\Finance\Reports\FinanceReports;
use App\Modules\Finance\Services\JournalApprovalService;
use App\Modules\Finance\Services\PostingService;
use App\Modules\Finance\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/** @see SRS M01-01, M01-02, M01-10, WF-03, M01-08, UAT-09 */
it('owner-paid expenses credit the Owner Current Account; drawings debit Owner Drawings and stay out of P&L', function (): void {
    postedTransaction(['payment_source' => 'OWNER_PAID', 'bank_account_id' => null, 'lines' => [['description' => 'Software paid with owner card', 'amount' => '250.00', 'category_id' => categoryId('software')]]]);
    expect(balanceOf('5050'))->toBe('250.00')->and(balanceOf('owner_current'))->toBe('-250.00')->and(balanceOf('1010'))->toBe('0.00');

    postedTransaction(['capitalization_class' => 'OWNER_DRAWING', 'lines' => [['description' => 'Owner personal groceries', 'amount' => '120.00']]]);
    expect(balanceOf('owner_drawings'))->toBe('120.00')->and(balanceOf('1010'))->toBe('-120.00');

    $pl = app(FinanceReports::class)->profitAndLoss(today()->startOfYear(), today());
    $cat = app(FinanceReports::class)->expensesByCategory(today()->startOfYear(), today());
    expect($pl['totals']['expenses'])->toBe('250.00')->and(collect($cat['rows'])->pluck('code')->all())->toBe(['5050'])
        ->and($cat['totals']['total'])->toBe('250.00');
})->group('M01-02', 'M01-10', 'WF-03', '§8.1');

it('refuses staff-paid expenses in the register and drawings paid by the owner', function (): void {
    $svc = app(TransactionService::class);
    $base = ['direction' => 'outflow', 'capitalization_class' => 'EXPENSE', 'transaction_date' => today()->toDateString(), 'lines' => [['description' => 'x', 'amount' => '1.00']]];
    expect(fn () => $svc->create($base + ['payment_source' => 'STAFF_PAID_REIMBURSABLE'], ownerUser()))->toThrow(PostingRejected::class, 'claim');
    expect(fn () => $svc->create(array_merge($base, ['payment_source' => 'OWNER_PAID', 'capitalization_class' => 'OWNER_DRAWING']), ownerUser()))->toThrow(PostingRejected::class, 'drawings');
    expect(fn () => DB::transaction(fn () => DB::table('transactions')->insert(['company_id' => 1, 'transaction_no' => 'TX-BAD', 'direction' => 'outflow', 'payment_source' => 'OWNER_PAID', 'capitalization_class' => 'OWNER_DRAWING', 'transaction_date' => today(), 'created_by' => 1])))
        ->toThrow(QueryException::class);
})->group('M01-02', 'M01-10');

it('capital purchases go to Fixed Assets with no expense line and announce the asset', function (): void {
    Event::fake([AssetQualifyingPurchaseRecorded::class]);
    $t = postedTransaction(['capitalization_class' => 'CAPITAL_ASSET', 'lines' => [['description' => 'Laptop Dell XPS 14', 'amount' => '6500.00']]]);
    expect(balanceOf('fixed_assets'))->toBe('6500.00')->and($t->tax_treatment->value)->toBe('capital')
        ->and(app(FinanceReports::class)->profitAndLoss(today()->startOfYear(), today())['totals']['expenses'])->toBe('0.00');
    Event::assertDispatched(AssetQualifyingPurchaseRecorded::class, fn ($e) => $e->sourceType === 'transaction' && $e->sourceId === $t->id && $e->amount === '6500.0000');
})->group('M01-10', 'M05-01', 'WF-02');

it('income: sales post to revenue, but capital, loans and refunds post to balance-sheet accounts', function (): void {
    foreach (['sales_training' => 'revenue_training', 'grant' => 'grants', 'capital_injection' => 'owner_capital', 'loan' => 'loan_payable', 'refund' => 'refunds_clearing'] as $type => $key) {
        postedTransaction(['direction' => 'inflow', 'inflow_type' => $type, 'payment_source' => null, 'capitalization_class' => null, 'lines' => [['description' => $type, 'amount' => '1000.00']]]);
        expect(balanceOf($key))->toBe('-1000.00');
    }
    expect(app(FinanceReports::class)->profitAndLoss(today()->startOfYear(), today())['totals']['income'])->toBe('2000.00');
})->group('M01-01');

it('UAT-09: after locking a period, its entries cannot be posted or edited — only reversed into an open period', function (): void {
    $owner = ownerUser();
    $oct = Carbon::parse('2026-10-15');
    $t = postedTransaction(['transaction_date' => $oct->toDateString()]);
    $period = FiscalPeriod::forDate($oct);
    app(FiscalPeriodService::class)->lock($period, $owner, 'October closed');

    // New postings into the locked period are refused (service + DB trigger)…
    expect(fn () => postedTransaction(['transaction_date' => '2026-10-20']))->toThrow(PostingRejected::class, 'locked');
    $journal = Journal::query()->findOrFail($t->journal_id);
    expect(fn () => DB::transaction(fn () => DB::table('journals')->where('id', $journal->id)->update(['memo' => 'edit'])))->toThrow(QueryException::class);
    // …but the authorised correction is a reversal, posted today in the open period.
    $reversed = app(TransactionService::class)->reverse($t, $owner, 'Wrong category');
    $rev = Journal::query()->findOrFail($reversed->reversal_journal_id);
    expect($reversed->status->value)->toBe('reversed')->and($rev->posting_date->toDateString())->toBe(today()->toDateString())
        ->and($rev->period->isLocked())->toBeFalse()->and(balanceOf('5040'))->toBe('0.00');

    // The DB trigger also refuses posting a draft into the locked period directly.
    $draftId = DB::table('journals')->insertGetId(['company_id' => 1, 'journal_no' => 'X-L', 'journal_date' => $oct, 'posting_date' => $oct, 'fiscal_period_id' => $period->id, 'source_type' => 'manual', 'status' => 'draft', 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => DB::transaction(fn () => DB::table('journals')->where('id', $draftId)->update(['status' => 'posted'])))->toThrow(QueryException::class, 'locked');
})->group('UAT-09', 'M01-08', 'WF-08');

it('adjusting journals are drafts that need approval by someone else; numbers are allocated only on posting', function (): void {
    $fin = userWithRoles('FIN');
    $svc = app(PostingService::class);
    $draft = $svc->createDraft(new JournalDraft(today(), 'manual', null, 'Reclass bank charges', [
        JournalLineDraft::debit(account('5120')->id, Money::myr('12.00')),
        JournalLineDraft::credit(account('5900')->id, Money::myr('12.00')),
    ]), $fin);
    expect($draft->status->value)->toBe('draft')->and($draft->journal_no)->toStartWith('DRAFT-')->and(balanceOf('5120'))->toBe('0.00');

    $journals = app(JournalApprovalService::class);
    expect(fn () => $journals->approve($draft, $fin))->toThrow(MakerCheckerViolation::class);
    $posted = $journals->approve($draft->fresh(), ownerUser());
    expect($posted->status->value)->toBe('posted')->and($posted->journal_no)->toStartWith('JV-2026-')->and(balanceOf('5120'))->toBe('12.00');
})->group('M01-08', 'WF-08', 'UAT-11');

it('tax treatment is a review flag: FIN/OWN/AUD may set it after posting, it never touches the ledger, and it is audited', function (): void {
    $t = postedTransaction();
    $lines = DB::table('journal_lines')->count();
    app(TransactionService::class)->setTaxTreatment($t, TaxTreatment::Deductible, userWithRoles('AUD'), 'Accountant review 2026');
    expect($t->fresh()->tax_treatment->value)->toBe('deductible')->and(DB::table('journal_lines')->count())->toBe($lines)
        ->and(AuditEvent::query()->where('action', 'tax_treatment')->where('entity_id', $t->id)->exists())->toBeTrue();
    expect(Gate::forUser(userWithRoles('AUD'))->allows('set-tax-treatment'))->toBeTrue()
        ->and(Gate::forUser(userWithRoles('PM'))->allows('set-tax-treatment'))->toBeFalse()
        ->and(Gate::forUser(userWithRoles('STF'))->allows('set-tax-treatment'))->toBeFalse();
})->group('M01-09');
