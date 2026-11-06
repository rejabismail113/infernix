<?php

declare(strict_types=1);

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Reports\ReportCatalog;
use App\Modules\Commercial\Models\VendorPurchaseOrder;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Services\BillService;
use App\Modules\Procurement\Exceptions\ProcurementRejected;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\Subscription;
use App\Modules\Procurement\Models\SubscriptionReview;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseRequestService;
use App\Modules\Procurement\Services\SubscriptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS M10-01 … M10-05, WF-02, WF-06, UAT-12 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});
afterEach(fn () => Carbon::setTestNow());

it('M10-02 / WF-02: PR → approval (maker-checker) → vendor PO → goods receipt → bill → payment, with a full audit chain', function (): void {
    $vendor = makeVendor('Office Pro');
    $requester = userWithRoles('STF');
    $prs = app(PurchaseRequestService::class);
    $pr = $prs->save(null, ['title' => 'Training room chairs', 'justification' => 'Replace broken chairs', 'vendor_id' => $vendor->id, 'lines' => [['description' => 'Ergonomic chair', 'quantity' => '6', 'estimated_unit_price' => '250.00']]], $requester);
    expect((string) $pr->estimated_total->getAmount())->toBe('1500.00')->and($pr->pr_no)->toStartWith('PR-');
    $prs->submit($pr, $requester);
    expect(fn () => $prs->approve($pr->fresh(), $requester))->toThrow(MakerCheckerViolation::class)
        ->and(fn () => $prs->approve($pr->fresh(), userWithRoles('STF')))->toThrow(AuthorizationException::class);
    $fin = userWithRoles('FIN');
    $prs->approve($pr->fresh(), $fin);
    $pr = $pr->fresh();
    expect($pr->status)->toBe('approved');
    expect(moneyRowCounts()['journals'])->toBe(0); // a PR never touches the ledger

    $poId = $prs->raisePurchaseOrder($pr, $vendor->id, $fin);
    expect($pr->fresh()->status)->toBe('ordered')->and($pr->fresh()->vendor_po_id)->toBe($poId);
    $po = VendorPurchaseOrder::query()->findOrFail($poId);
    expect($po->source_type)->toBe('purchase_request')->and($po->source_id)->toBe($pr->id)->and((string) $po->total->getAmount())->toBe('1500.00');
    expect(fn () => app(GoodsReceiptService::class)->record($poId, ['received_on' => today()->toDateString(), 'kind' => 'goods', 'lines' => []], $fin))->toThrow(ProcurementRejected::class, 'issued');
    $commercial = app(CommercialDocumentService::class);
    $commercial->submit($po, $fin);
    approveFully($po, userWithRoles('FIN'));
    $commercial->issue($po->fresh(), $fin);
    expect(moneyRowCounts()['journals'])->toBe(0); // a vendor PO is a commitment only

    $poLine = $po->lines()->firstOrFail();
    $grns = app(GoodsReceiptService::class);
    expect(fn () => $grns->record($poId, ['received_on' => today()->toDateString(), 'kind' => 'goods', 'lines' => [['po_line_id' => $poLine->id, 'quantity_received' => '7']]], $fin))->toThrow(ProcurementRejected::class, 'more than ordered');
    $grn = $grns->record($poId, ['received_on' => today()->toDateString(), 'kind' => 'goods', 'lines' => [['po_line_id' => $poLine->id, 'quantity_received' => '6', 'condition' => 'Good']]], $fin);
    expect($grn->grn_no)->toStartWith('GRN-');

    // The bill form is pre-filled from the receipt and the bill carries the PO + receipt references.
    actingAsSatisfied($fin)->get(route('finance.bills.create', ['goods_receipt_id' => $grn->id]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('prefill.vendor_po_id', $poId)->where('prefill.lines.0.unit_price', '250.00')->where('prefill.lines.0.quantity', '6.0000'));
    actingAsSatisfied($fin)->post(route('finance.bills.store'), ['vendor_id' => $vendor->id, 'vendor_invoice_no' => 'OP-7781', 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(),
        'goods_receipt_id' => $grn->id, 'lines' => [['description' => 'Ergonomic chair', 'quantity' => '6', 'unit_price' => '250.00', 'capitalization_class' => 'LOW_VALUE_EQUIPMENT']]])->assertRedirect();
    $bill = Bill::query()->where('vendor_invoice_no', 'OP-7781')->firstOrFail();
    expect($bill->vendor_po_id)->toBe($poId)->and($bill->goods_receipt_id)->toBe($grn->id);
    $billSvc = app(BillService::class);
    $billSvc->submit($bill, $fin);
    $billSvc->approve($bill->fresh(), ownerUser());
    payVendorBill($bill->fresh(), '1500.00');

    $chain = app(FinanceRecords::class)->billsForVendorPo($poId);
    expect($chain)->toHaveCount(1)->and($chain[0]['status'])->toBe('paid')->and($chain[0]['payments'])->toHaveCount(1);
    actingAsSatisfied($fin)->get(route('procurement.requests.show', $pr->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('po.id', $poId)->has('receipts', 1)->has('bills', 1)->where('bills.0.payments.0.amount', '1500.00'));
    $pipeline = app(ReportCatalog::class)->find('procurement.pipeline')?->run(['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]);
    expect($pipeline['rows'][0])->toMatchArray(['status' => 'ordered', 'billed' => '1500.00', 'paid' => '1500.00']);
    // A receipt for another vendor cannot be billed under this vendor.
    actingAsSatisfied($fin)->post(route('finance.bills.store'), ['vendor_id' => makeVendor()->id, 'vendor_invoice_no' => 'X-1', 'bill_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
        'goods_receipt_id' => $grn->id, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '1.00']]])->assertSessionHasErrors('general');
    expect(GoodsReceipt::query()->count())->toBe(1);
})->group('M10-02', 'WF-02', 'UAT-11', 'M03-05');

it('M10-03: a vendor invoice cannot be recorded twice — service (any case/spacing) and the DB unique index', function (): void {
    $vendor = makeVendor();
    approvedVendorBill($vendor, [['description' => 'Toner', 'quantity' => '1', 'unit_price' => '300.00']], 'INV-1001');
    $svc = app(BillService::class);
    expect(fn () => $svc->create(['vendor_id' => $vendor->id, 'vendor_invoice_no' => ' inv-1001 ', 'bill_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'lines' => [['description' => 'Toner', 'quantity' => '1', 'unit_price' => '300.00']]], ownerUser()))
        ->toThrow(PostingRejected::class, 'already recorded');
    $existing = Bill::query()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(['company_id' => $existing->company_id, 'bill_no' => 'BL-DUP', 'vendor_id' => $vendor->id, 'vendor_invoice_no' => 'Inv-1001',
        'bill_date' => today(), 'due_date' => today(), 'created_by' => ownerUser()->id])))->toThrow(QueryException::class);
    // The same number from a DIFFERENT vendor is fine.
    approvedVendorBill(makeVendor(), [['description' => 'Toner', 'quantity' => '1', 'unit_price' => '300.00']], 'INV-1001');
    expect(Bill::query()->count())->toBe(2);
})->group('M10-03', 'M01-05');

it('UAT-12 / WF-06: a subscription renewal alert creates a review task and a notification — never a finance transaction', function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-04-01 09:00:00');
    $owner = userWithRoles('STF');
    $svc = app(SubscriptionService::class);
    $sub = $svc->save(null, ['vendor_id' => makeVendor('Canva')->id, 'product' => 'Canva Teams', 'license_owner_staff_id' => makeStaff($owner)->id, 'seats' => 5, 'price' => '1200.00', 'currency' => 'MYR',
        'billing_cycle' => 'annual', 'auto_renew' => true, 'renewal_date' => '2026-06-15'], ownerUser());
    $before = moneyRowCounts();

    expect($svc->runRenewalAlerts())->toBe(1); // 75 days out → 90-day threshold
    $review = SubscriptionReview::query()->where('subscription_id', $sub->id)->firstOrFail();
    expect($review->status)->toBe('open')->and($review->alert_level)->toBe(90)->and($sub->fresh()->status)->toBe('under_review')
        ->and(moneyRowCounts())->toBe($before)
        ->and(DB::table('notification_dispatches')->where('user_id', $owner->id)->where('dedupe_key', 'like', 'subscription:%')->exists())->toBeTrue();
    expect($svc->runRenewalAlerts())->toBe(0); // idempotent: still one review

    Carbon::setTestNow('2026-05-20 09:00:00'); // 26 days → escalates to the 30-day alert, same review
    $svc->runRenewalAlerts();
    expect(SubscriptionReview::query()->where('subscription_id', $sub->id)->count())->toBe(1)->and($review->fresh()->alert_level)->toBe(30)->and(moneyRowCounts())->toBe($before);

    // Deciding to renew still books nothing; the renewal date rolls forward.
    $svc->decide($review, 'renew', 'Still needed', ownerUser());
    expect($sub->fresh()->renewal_date->toDateString())->toBe('2027-06-15')->and($sub->fresh()->status)->toBe('active')->and(moneyRowCounts())->toBe($before);

    // Money appears only when the supplier bill is recorded in Finance — and is then linked.
    $bill = approvedVendorBill(Subscription::query()->findOrFail($sub->id)->vendor, [['description' => 'Canva Teams 2026/27', 'quantity' => '1', 'unit_price' => '1200.00', 'category_id' => categoryId('software')]], 'CANVA-2026');
    $svc->linkFinance($sub->fresh(), 'bill', $bill->id, ownerUser());
    expect(moneyRowCounts()['bills'])->toBe($before['bills'] + 1)->and(app(FinanceRecords::class)->linkedTotals('subscription', [$sub->id])[$sub->id])->toBe('1200.00')
        ->and(balanceOf('5050'))->toBe('1200.00');
    $calendar = app(ReportCatalog::class)->find('procurement.renewal-calendar')?->run(['from' => '2026-01-01', 'to' => '2027-12-31']);
    expect($calendar['rows'][0])->toMatchArray(['product' => 'Canva Teams', 'renewal_date' => '2027-06-15', 'annualised' => '1200.00']);
})->group('UAT-12', 'WF-06', 'M10-04', 'M10-05');

it('M10-01: vendor profile keeps the bank reference masked and STF only see subscriptions they own', function (): void {
    $vendor = makeVendor('Telco');
    $vendor->forceFill(['bank_reference' => '5140 1234 9876', 'category' => 'telecom'])->save();
    $page = actingAsSatisfied(ownerUser())->get(route('procurement.vendors.show', $vendor->id))->assertOk();
    expect($page->getContent())->not->toContain('5140 1234 9876')->toContain('9876');
    actingAsSatisfied(userWithRoles('STF'))->get(route('procurement.vendors.show', $vendor->id))->assertForbidden();

    $me = userWithRoles('STF');
    $svc = app(SubscriptionService::class);
    $svc->save(null, ['vendor_id' => $vendor->id, 'product' => 'Mobile plan', 'license_owner_staff_id' => makeStaff($me)->id, 'seats' => 1, 'price' => '99.00', 'currency' => 'MYR', 'billing_cycle' => 'monthly', 'renewal_date' => '2026-12-01'], ownerUser());
    $svc->save(null, ['vendor_id' => $vendor->id, 'product' => 'Other plan', 'seats' => 1, 'price' => '99.00', 'currency' => 'MYR', 'billing_cycle' => 'monthly', 'renewal_date' => '2026-12-01'], ownerUser());
    actingAsSatisfied($me)->get(route('procurement.subscriptions.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('subscriptions', 1)->where('subscriptions.0.product', 'Mobile plan'));
})->group('M10-01', 'M10-04', 'NFR-02');
