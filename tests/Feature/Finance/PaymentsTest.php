<?php

declare(strict_types=1);

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Models\User;
use App\Kernel\StateMachine\IllegalTransition;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\BillService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** @see SRS M01-05, M10-03, UAT-11, §6.3 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
    $this->vendor = Vendor::query()->create(['company_id' => ownerUser()->company_id, 'name' => 'Acme Supplies Sdn Bhd', 'status' => 'active']);
});

function approvedBill(Vendor $vendor, string $amount = '1000.00', string $invoiceNo = 'INV-889'): Bill
{
    $fin1 = userWithRoles('FIN');
    $svc = app(BillService::class);
    $bill = $svc->create(['vendor_id' => $vendor->id, 'vendor_invoice_no' => $invoiceNo, 'bill_date' => today()->toDateString(), 'due_date' => today()->addDays(30)->toDateString(),
        'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => $amount, 'category_id' => categoryId('professional')]]], $fin1);
    $svc->submit($bill, $fin1);

    return $svc->approve($bill->fresh(), ownerUser())->fresh();
}

function payBill(Bill $bill, string $amount, ?User $maker = null): Payment
{
    $maker ??= userWithRoles('FIN');
    $svc = app(PaymentService::class);
    $p = $svc->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'payee_type' => 'vendor', 'payee_id' => $bill->vendor_id, 'payee_name' => 'Acme', 'allocations' => [['target_type' => 'bill', 'target_id' => $bill->id, 'amount' => $amount]]], $maker);
    $svc->submit($p, $maker);
    $svc->approve($p->fresh(), ownerUser());

    return $svc->post($p->fresh(), $maker);
}

it('bill approval posts Dr expense / Cr AP; partial payments settle it and the ceiling is enforced', function (): void {
    $bill = approvedBill($this->vendor);
    expect(balanceOf('5060'))->toBe('1000.00')->and(balanceOf('accounts_payable'))->toBe('-1000.00');

    payBill($bill, '400.00');
    expect($bill->fresh()->status->value)->toBe('partially_paid')->and((string) $bill->fresh()->outstanding()->getAmount())->toBe('600.00');
    payBill($bill->fresh(), '600.00');
    expect($bill->fresh()->status->value)->toBe('paid')->and(balanceOf('accounts_payable'))->toBe('0.00')->and(balanceOf('1010'))->toBe('-1000.00')
        ->and(balanceOf('5060'))->toBe('1000.00'); // still one expense

    expect(fn () => payBill($bill->fresh(), '0.01'))->toThrow(PostingRejected::class);
})->group('M01-05', 'M10-03', '§6.3');

it('two open payments cannot together exceed a bill; the DB trigger also refuses a direct over-allocation', function (): void {
    $bill = approvedBill($this->vendor, '500.00');
    $svc = app(PaymentService::class);
    $fin = userWithRoles('FIN');
    $make = fn (string $amt) => $svc->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'payee_type' => 'vendor', 'payee_id' => $this->vendor->id, 'payee_name' => 'Acme', 'allocations' => [['target_type' => 'bill', 'target_id' => $bill->id, 'amount' => $amt]]], $fin);

    $first = $make('300.00');
    expect(fn () => $make('300.00'))->toThrow(PostingRejected::class, 'exceeds');
    $make('200.00');

    expect(fn () => DB::transaction(fn () => DB::table('payment_allocations')->insert(['payment_id' => $first->id, 'target_type' => 'bill', 'target_id' => $bill->id + 0, 'amount' => '0.01'])))
        ->toThrow(QueryException::class);
    // Cancelling a payment releases its allocation.
    $svc->cancel($first, $fin, 'Duplicate');
    $make('300.00');
    expect(fn () => DB::transaction(fn () => DB::table('payment_allocations')->insert(['payment_id' => $first->id, 'target_type' => 'claim', 'target_id' => 999999, 'amount' => '1'])))
        ->toThrow(QueryException::class);
})->group('M10-03', '§6.3', 'M01-05');

it('M10-03: the same vendor invoice cannot be recorded (and so paid) twice', function (): void {
    approvedBill($this->vendor, '100.00', 'INV-777');
    expect(fn () => approvedBill($this->vendor, '100.00', 'inv-777 '))->toThrow(PostingRejected::class, 'already recorded');
    expect(fn () => DB::transaction(fn () => DB::table('bills')->insert(['company_id' => 1, 'bill_no' => 'BL-X', 'vendor_id' => $this->vendor->id, 'vendor_invoice_no' => 'INV-777', 'bill_date' => today(), 'due_date' => today(), 'created_by' => 1])))
        ->toThrow(QueryException::class);
})->group('M10-03');

it('UAT-11: the preparer cannot approve their own payment; Owner override needs a reason and is audited', function (): void {
    $bill = approvedBill($this->vendor, '200.00');
    $svc = app(PaymentService::class);
    $fin = userWithRoles('FIN');
    $p = $svc->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'payee_type' => 'vendor', 'payee_id' => $this->vendor->id, 'payee_name' => 'Acme', 'allocations' => [['target_type' => 'bill', 'target_id' => $bill->id, 'amount' => '200.00']]], $fin);
    $svc->submit($p, $fin);
    expect(fn () => $svc->approve($p->fresh(), $fin))->toThrow(MakerCheckerViolation::class);
    expect($p->fresh()->status->value)->toBe('pending_approval');

    $owner = ownerUser();
    $q = $svc->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'payee_type' => 'vendor', 'payee_id' => $this->vendor->id, 'payee_name' => 'Acme', 'allocations' => [['target_type' => 'bill', 'target_id' => approvedBill($this->vendor, '50.00', 'INV-2')->id, 'amount' => '50.00']]], $owner);
    $svc->submit($q, $owner);
    expect(fn () => $svc->approve($q->fresh(), $owner))->toThrow(MakerCheckerViolation::class);
    $svc->approve($q->fresh(), $owner, 'Urgent supplier payment while FIN on leave');
    expect($q->fresh()->status->value)->toBe('approved')->and($q->fresh()->owner_override)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'owner_override')->where('entity_type', Payment::class)->where('entity_id', $q->id)->exists())->toBeTrue();
    // A plain FIN approver (not the maker) is fine.
    $svc->approve($p->fresh(), userWithRoles('FIN'));
    expect($p->fresh()->status->value)->toBe('approved');
})->group('UAT-11', '§2.1');

it('a payment cannot be posted before approval, and posting generates a frozen voucher PDF', function (): void {
    $bill = approvedBill($this->vendor, '80.00');
    $svc = app(PaymentService::class);
    $fin = userWithRoles('FIN');
    $p = $svc->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'duitnow', 'payee_type' => 'vendor', 'payee_id' => $this->vendor->id, 'payee_name' => 'Acme', 'allocations' => [['target_type' => 'bill', 'target_id' => $bill->id, 'amount' => '80.00']]], $fin);
    expect(fn () => $svc->post($p, $fin))->toThrow(IllegalTransition::class);
    $svc->submit($p, $fin);
    $svc->approve($p->fresh(), ownerUser());
    $posted = $svc->post($p->fresh(), $fin);
    $doc = Document::query()->findOrFail($posted->voucher_document_id);
    expect($doc->frozen)->toBeTrue()->and($doc->versions()->first()->mime_type)->toBe('application/pdf')->and($doc->title)->toContain($posted->payment_no);
})->group('M03-06', 'M01-05', 'M06-03');
