<?php

declare(strict_types=1);

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Money\Money;
use App\Kernel\Reports\ReportCatalog;
use App\Modules\Assets\Exceptions\AssetRejected;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCandidate;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Assets\Services\DepreciationService;
use App\Modules\Assets\Services\DisposalService;
use App\Modules\Finance\Models\Bill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** @see SRS M05-01 … M05-05, WF-02, UAT-03 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

/** Sum of every expense-account balance (what the P&L would show). */
function totalExpense(): string
{
    $r = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('chart_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('a.type', 'expense')->where('j.status', '!=', 'draft')->selectRaw('COALESCE(SUM(l.debit),0)-COALESCE(SUM(l.credit),0) AS b')->first();

    return (string) Money::fromDb((string) $r->b)->getAmount();
}

function laptopBill(string $amount = '4500.00', string $invoice = 'LAP-001'): Bill
{
    return approvedVendorBill(makeVendor('Laptop World'), [['description' => 'Laptop 14"', 'quantity' => '1', 'unit_price' => $amount, 'capitalization_class' => 'CAPITAL_ASSET']], $invoice);
}

it('UAT-03: laptop bought on the company account → vendor payment + asset registration, counted once', function (): void {
    $bill = laptopBill();
    payVendorBill($bill, '4500.00');
    expect(balanceOf('fixed_assets'))->toBe('4500.00')->and(totalExpense())->toBe('0.00')->and(balanceOf('accounts_payable'))->toBe('0.00')->and(balanceOf('1010'))->toBe('-4500.00');

    $candidate = AssetCandidate::query()->where('source_type', 'bill')->where('source_id', $bill->id)->firstOrFail();
    $before = moneyRowCounts();
    $category = AssetCategory::query()->where('name', 'Computers & laptops')->firstOrFail();
    $asset = app(AssetService::class)->registerFromCandidate($candidate, ['name' => 'Dell Latitude', 'category_id' => $category->id, 'serial_no' => 'SN123'], ownerUser());

    // Registration references the purchase and books nothing.
    expect(moneyRowCounts())->toBe($before)
        ->and($asset->purchase_bill_id)->toBe($bill->id)->and($asset->purchase_source_type)->toBe('bill')->and((string) $asset->cost->getAmount())->toBe('4500.00')
        ->and($candidate->fresh()->status)->toBe('registered')
        ->and(balanceOf('fixed_assets'))->toBe('4500.00')->and(totalExpense())->toBe('0.00')
        ->and(AssetDepreciation::query()->where('asset_id', $asset->id)->count())->toBe(36);

    // The same purchase cannot be capitalised twice — service and DB trigger both refuse.
    expect(fn () => app(AssetService::class)->registerFromCandidate($candidate->fresh(), ['name' => 'Again'], ownerUser()))->toThrow(AssetRejected::class);
    $line = $bill->lines()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('assets')->insert(['company_id' => $asset->company_id, 'asset_no' => 'AST-X', 'name' => 'dup', 'acquisition_type' => 'purchase',
        'purchase_source_type' => 'bill', 'purchase_bill_id' => $bill->id, 'purchase_line_id' => $line->id, 'acquired_on' => today(), 'cost' => '1.00', 'created_by' => ownerUser()->id])))->toThrow(QueryException::class);
    // A purchased asset cannot be keyed in manually either.
    expect(fn () => app(AssetService::class)->registerManual(['acquisition_type' => 'purchase', 'name' => 'x', 'cost' => '1'], ownerUser()))->toThrow(AssetRejected::class);
})->group('UAT-03', 'M05-01', 'WF-02');

it('M05-01: a capital expense paid directly also becomes a candidate; a multi-unit line can be split into several assets up to its amount', function (): void {
    $t = postedTransaction(['capitalization_class' => 'CAPITAL_ASSET', 'lines' => [['description' => '3 x monitors', 'amount' => '3000.00']]]);
    $c = AssetCandidate::query()->where('source_type', 'transaction')->where('source_id', $t->id)->firstOrFail();
    $svc = app(AssetService::class);
    $a1 = $svc->registerFromCandidate($c, ['name' => 'Monitor 1', 'cost' => '1000.00'], ownerUser());
    $svc->registerFromCandidate($c->fresh(), ['name' => 'Monitor 2', 'cost' => '1000.00'], ownerUser());
    expect($c->fresh()->status)->toBe('pending')->and($a1->purchase_transaction_id)->toBe($t->id);
    expect(fn () => $svc->registerFromCandidate($c->fresh(), ['name' => 'Monitor 3', 'cost' => '1000.01'], ownerUser()))->toThrow(AssetRejected::class);
    $svc->registerFromCandidate($c->fresh(), ['name' => 'Monitor 3'], ownerUser());
    expect($c->fresh()->status)->toBe('registered')->and(Asset::query()->where('purchase_transaction_id', $t->id)->count())->toBe(3);
    // A non-capital expense is not offered for registration.
    $plain = postedTransaction();
    expect(AssetCandidate::query()->where('source_type', 'transaction')->where('source_id', $plain->id)->exists())->toBeFalse();
})->group('M05-01', 'M05-02');

it('M05-02: capitalisation policy classifies by unit cost (PLACEHOLDER thresholds)', function (): void {
    expect(AssetService::classify('2500.00'))->toBe('capital_asset')->and(AssetService::classify('800.00'))->toBe('low_value_equipment')->and(AssetService::classify('120.00'))->toBe('consumable');
})->group('M05-02');

it('M05-03: assignment, handover and return keep a custodian history; repair cost is a link to an existing expense', function (): void {
    $asset = app(AssetService::class)->registerManual(['acquisition_type' => 'opening_balance', 'name' => 'Projector', 'cost' => '1800.00', 'acquired_on' => '2025-06-01', 'useful_life_months' => 24], ownerUser());
    $staff = makeStaff(userWithRoles('STF'));
    $svc = app(AssetService::class);
    $svc->assign($asset, ['staff_profile_id' => $staff->id, 'assigned_on' => '2026-02-01', 'condition_out' => 'Good'], ownerUser());
    expect($asset->fresh()->status)->toBe('assigned')->and($asset->fresh()->custodian_staff_id)->toBe($staff->id);
    expect(fn () => $svc->assign($asset->fresh(), ['location' => 'Store', 'assigned_on' => '2026-02-02'], ownerUser()))->toThrow(AssetRejected::class);
    $svc->returnAsset($asset->fresh(), ['returned_on' => '2026-03-01', 'condition_in' => 'Scratched'], ownerUser());
    expect($asset->fresh()->status)->toBe('in_store')->and($asset->fresh()->custodian_staff_id)->toBeNull()->and($asset->assignments()->count())->toBe(1);

    $m = $svc->logMaintenance($asset->fresh(), ['kind' => 'repair', 'reported_on' => '2026-03-02', 'description' => 'Lamp replacement'], ownerUser());
    expect($asset->fresh()->status)->toBe('in_repair');
    $repair = postedTransaction(['lines' => [['description' => 'Projector lamp', 'amount' => '250.00', 'category_id' => categoryId('office')]]]);
    $before = moneyRowCounts();
    $svc->linkRepairCost($m, 'transaction', $repair->id, ownerUser());
    expect(moneyRowCounts())->toBe($before)->and(app(FinanceRecords::class)->linkedTotals('asset_maintenance', [$m->id])[$m->id])->toBe('250.00');
    $svc->completeMaintenance($m->fresh(), '2026-03-05', ownerUser());
    expect($asset->fresh()->status)->toBe('in_store');
})->group('M05-03', 'M05-05');

it('M05-04: disposal needs maker-checker approval and posts the derecognition journal through PostingService', function (): void {
    $c = AssetCandidate::query()->where('source_id', laptopBill('3600.00', 'LAP-9')->id)->firstOrFail();
    $asset = app(AssetService::class)->registerFromCandidate($c, ['name' => 'Laptop'], ownerUser());
    $fin = userWithRoles('FIN');
    $svc = app(DisposalService::class);
    $d = $svc->create($asset, ['method' => 'scrap', 'disposal_date' => today()->toDateString(), 'reason' => 'Beyond repair'], $fin);
    $svc->submit($d, $fin);
    expect(fn () => $svc->approve($d->fresh(), $fin))->toThrow(MakerCheckerViolation::class);
    $svc->approve($d->fresh(), ownerUser());

    $d = $d->fresh();
    $journal = app(FinanceRecords::class)->journalFor('asset_disposal', $d->id);
    expect($d->status)->toBe('approved')->and($journal)->not->toBeNull()->and($d->journal_id)->toBe($journal['id'])
        ->and($asset->fresh()->status)->toBe('disposed')
        ->and(balanceOf('fixed_assets'))->toBe('0.00')->and(balanceOf('loss_on_disposal'))->toBe('3600.00')->and((string) $d->nbv_snapshot?->getAmount())->toBe('3600.00');
    expect(fn () => $svc->create($asset->fresh(), ['method' => 'sale', 'disposal_date' => today()->toDateString(), 'reason' => 'x'], $fin))->toThrow(AssetRejected::class);
})->group('M05-04', 'UAT-11', 'M01-06');

it('M05-04: depreciation is scheduled straight-line; journals are posted only with the feature flag', function (): void {
    $asset = app(AssetService::class)->registerManual(['acquisition_type' => 'opening_balance', 'name' => 'Server', 'asset_class' => 'capital_asset', 'cost' => '1000.00', 'acquired_on' => '2026-01-15', 'useful_life_months' => 3], ownerUser());
    $rows = AssetDepreciation::query()->where('asset_id', $asset->id)->orderBy('period_end')->get();
    expect($rows->map(fn ($r) => (string) $r->amount->getAmount())->all())->toBe(['333.33', '333.33', '333.34'])->and((string) $rows->last()->nbv->getAmount())->toBe('0.00');

    $journals = DB::table('journals')->count();
    expect(app(DepreciationService::class)->postDue(Carbon::parse('2026-03-31'), ownerUser()))->toBe(0)->and(DB::table('journals')->count())->toBe($journals);

    config(['infernix.features.depreciation_posting' => true]);
    expect(app(DepreciationService::class)->postDue(Carbon::parse('2026-02-28'), ownerUser()))->toBe(2)
        ->and(balanceOf('depreciation_expense'))->toBe('666.66')->and(balanceOf('accumulated_depreciation'))->toBe('-666.66');
    // Posted rows are immutable (DB trigger).
    expect(fn () => DB::transaction(fn () => DB::table('asset_depreciation')->where('asset_id', $asset->id)->whereNotNull('journal_id')->update(['amount' => '1'])))->toThrow(QueryException::class);
})->group('M05-04');

it('M05: the register, custodian, warranty and disposal reports run; STF sees only assets in their custody', function (): void {
    $stfUser = userWithRoles('STF');
    $staff = makeStaff($stfUser);
    $svc = app(AssetService::class);
    $mine = $svc->registerManual(['acquisition_type' => 'donation', 'name' => 'Phone', 'cost' => '900.00', 'acquired_on' => '2026-01-01', 'warranty_expires_on' => today()->addDays(20)->toDateString()], ownerUser());
    $svc->assign($mine, ['staff_profile_id' => $staff->id, 'assigned_on' => '2026-01-02'], ownerUser());
    $other = $svc->registerManual(['acquisition_type' => 'donation', 'name' => 'Tablet', 'cost' => '900.00', 'acquired_on' => '2026-01-01'], ownerUser());

    actingAsSatisfied($stfUser)->get(route('assets.assets.index'))->assertOk()->assertInertia(fn ($p) => $p->has('assets', 1)->where('assets.0.id', $mine->id));
    actingAsSatisfied($stfUser)->get(route('assets.assets.show', $other->id))->assertForbidden();
    actingAsSatisfied($stfUser)->get(route('assets.assets.show', $mine->id))->assertOk();
    foreach (['assets.register', 'assets.custodians', 'assets.warranty', 'assets.disposals'] as $key) {
        actingAsSatisfied(ownerUser())->get(route('reports.view', $key))->assertOk();
    }
    $warranty = app(ReportCatalog::class)->find('assets.warranty')?->run(['from' => today()->toDateString(), 'to' => today()->addDays(60)->toDateString()]);
    expect(collect($warranty['rows'] ?? [])->pluck('name')->all())->toBe(['Phone']);
})->group('M05-03', 'M05-05', 'NFR-02');
