<?php

declare(strict_types=1);

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Services\DelegationService;
use App\Kernel\Money\Money;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\ClaimService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS UAT-02, M01-03, M01-04, WF-01, UAT-11 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

function claimTypeId(string $code): int
{
    return (int) ClaimType::query()->where('code', $code)->value('id');
}

it('UAT-02: mileage claim → approval → payout has a single expense impact, a linked payment and a complete audit trail', function (): void {
    $staff = userWithRoles('STF');
    $fin1 = userWithRoles('FIN');
    $fin2 = userWithRoles('FIN');
    $claims = app(ClaimService::class);

    $claim = $claims->create($staff, ['purpose' => 'Client site visit', 'lines' => [[
        'claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'KL → Shah Alam return',
        'origin' => 'Kuala Lumpur', 'destination' => 'Shah Alam', 'distance_km' => '100',
    ]]], $staff);
    expect((string) $claim->total_amount->getAmount())->toBe('60.00'); // 100 km × RM 0.60 (PLACEHOLDER rate v1)

    $claims->submit($claim, $staff);
    $line = $claim->lines()->first();
    expect($claim->fresh()->status->value)->toBe('pending_approval')
        ->and((string) $line->rate_snapshot?->getAmount())->toBe('0.60')->and($line->rate_version_id)->not->toBeNull();

    $claims->approve($claim->fresh(), $fin1);
    $claim->refresh();
    expect($claim->status->value)->toBe('finance_payable')->and((string) $claim->approved_amount->getAmount())->toBe('60.00')
        ->and(balanceOf('5010'))->toBe('60.00')->and(balanceOf('staff_claims_payable'))->toBe('-60.00');

    $pay = app(PaymentService::class);
    $payment = $pay->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'reference' => 'DuitNow 123',
        'payee_type' => 'staff', 'payee_id' => $staff->id, 'payee_name' => $staff->name, 'allocations' => [['target_type' => 'claim', 'target_id' => $claim->id, 'amount' => '60.00']]], $fin1);
    $pay->submit($payment, $fin1);
    $pay->approve($payment->fresh(), $fin2);
    $pay->post($payment->fresh(), $fin1);
    $claim->refresh();
    $payment->refresh();

    // Single expense impact: travel expense is 60 exactly once; payable cleared; bank down 60.
    $expenseTotal = DB::table('journal_lines')->join('chart_accounts', 'chart_accounts.id', '=', 'journal_lines.account_id')->where('chart_accounts.type', 'expense')->sum('debit');
    expect($claim->status->value)->toBe('paid')->and((string) $claim->paid_amount->getAmount())->toBe('60.00')
        ->and(balanceOf('5010'))->toBe('60.00')->and(Money::fromDb((string) $expenseTotal)->getAmount()->__toString())->toBe('60.00')
        ->and(balanceOf('staff_claims_payable'))->toBe('0.00')->and(balanceOf('1010'))->toBe('-60.00')
        ->and($payment->status->value)->toBe('paid')->and($payment->allocations()->first()->target_id)->toBe($claim->id)
        ->and(Journal::query()->where('source_type', 'claim')->where('source_id', $claim->id)->count())->toBe(1)
        ->and(Journal::query()->where('source_type', 'payment')->where('source_id', $payment->id)->count())->toBe(1)
        ->and($payment->voucher_document_id)->not->toBeNull();

    $actions = AuditEvent::query()->where(fn ($q) => $q->where('entity_type', Claim::class)->where('entity_id', $claim->id))->pluck('action')->all();
    expect($actions)->toContain('create', 'transition', 'approve');
    expect(AuditEvent::query()->where('action', 'post')->where('entity_type', Journal::class)->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'approve')->where('entity_type', Payment::class)->exists())->toBeTrue();
})->group('UAT-02', 'M01-03', 'M01-04', 'M01-05', 'WF-01', 'G-06');

it('mileage uses the rate version in force on the travel date and snapshots it', function (): void {
    $mileage = ClaimType::query()->where('code', 'mileage')->firstOrFail();
    $latest = $mileage->rates()->first();
    $latest->forceFill(['effective_to' => '2026-07-01'])->save();
    $mileage->rates()->create(['rate' => '0.80', 'unit' => 'km', 'effective_from' => '2026-07-01', 'version' => 2]);
    $staff = userWithRoles('STF');
    $c = app(ClaimService::class)->create($staff, ['purpose' => 'Trips', 'lines' => [
        ['claim_type_id' => $mileage->id, 'incurred_on' => '2026-06-15', 'description' => 'June', 'distance_km' => '10'],
        ['claim_type_id' => $mileage->id, 'incurred_on' => '2026-08-15', 'description' => 'August', 'distance_km' => '10'],
    ]], $staff);
    expect($c->lines()->pluck('amount')->map(fn ($m) => (string) $m->getAmount())->all())->toBe(['6.00', '8.00']);
})->group('M01-04', 'G-09');

it('requires a receipt per claim-type policy before submission', function (): void {
    $staff = userWithRoles('STF');
    $claims = app(ClaimService::class);
    $c = $claims->create($staff, ['purpose' => 'Lunch with client', 'lines' => [['claim_type_id' => claimTypeId('meals'), 'incurred_on' => today()->toDateString(), 'description' => 'Lunch', 'amount' => '45.90']]], $staff);
    expect(fn () => $claims->submit($c, $staff))->toThrow(PostingRejected::class, 'receipt is required for line 1');

    $line = $c->lines()->first();
    app(DocumentStore::class)->attach(Files::jpg('receipt.jpg'), $staff, ['title' => 'Lunch receipt'], new LinkTarget('claim_line', $line->id, 'receipt'));
    $claims->submit($c->fresh(), $staff);
    expect($c->fresh()->status->value)->toBe('pending_approval');
})->group('M01-03', 'WF-01', 'M06-01');

it('rejected lines can be corrected and resubmitted', function (): void {
    $staff = userWithRoles('STF');
    $fin = userWithRoles('FIN');
    $claims = app(ClaimService::class);
    $c = $claims->create($staff, ['purpose' => 'Trip', 'lines' => [['claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'Trip', 'distance_km' => '500']]], $staff);
    $claims->submit($c, $staff);
    $line = $c->lines()->first();
    $claims->reject($c->fresh(), $fin, 'Distance looks too high', [$line->id => 'Use the actual odometer reading']);
    expect($c->fresh()->status->value)->toBe('rejected')->and($line->fresh()->status)->toBe('rejected');

    $claims->update($c->fresh(), ['lines' => [['id' => $line->id, 'claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'Trip (corrected)', 'distance_km' => '50']]], $staff);
    $claims->submit($c->fresh(), $staff);
    expect($c->fresh()->status->value)->toBe('pending_approval')->and((string) $c->fresh()->total_amount->getAmount())->toBe('30.00')->and($line->fresh()->status)->toBe('pending');
    $claims->approve($c->fresh(), $fin);
    expect($c->fresh()->status->value)->toBe('finance_payable');
})->group('WF-01', 'M01-03');

it('UAT-11: the claimant cannot approve their own claim; an Owner may only with a recorded reason', function (): void {
    $fin = userWithRoles('FIN');
    $claims = app(ClaimService::class);
    $c = $claims->create($fin, ['purpose' => 'FIN own trip', 'lines' => [['claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '10']]], $fin);
    $claims->submit($c, $fin);
    expect(fn () => $claims->approve($c->fresh(), $fin))->toThrow(MakerCheckerViolation::class);

    $owner = ownerUser();
    $oc = $claims->create($owner, ['purpose' => 'Owner trip', 'lines' => [['claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '10']]], $owner);
    $claims->submit($oc, $owner);
    expect(fn () => $claims->approve($oc->fresh(), $owner))->toThrow(MakerCheckerViolation::class);
    $claims->approve($oc->fresh(), $owner, 'Sole proprietor; no other approver available this month');
    expect($oc->fresh()->status->value)->toBe('finance_payable')
        ->and($oc->approvals()->first()->owner_override)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'owner_override')->where('entity_id', $oc->id)->where('reason', 'like', 'Sole proprietor%')->exists())->toBeTrue();
})->group('UAT-11', '§2.1');

it('honours delegation: an APP delegate approves within the ceiling only', function (): void {
    $fin = userWithRoles('FIN');
    $app = userWithRoles('APP');
    app(DelegationService::class)->create(['delegator_id' => $fin->id, 'delegate_id' => $app->id, 'entity_type' => 'claim', 'max_amount' => Money::myr('50'), 'valid_from' => now()->subDay(), 'valid_to' => now()->addWeek(), 'reason' => 'Leave'], $fin);
    $staff = userWithRoles('STF');
    $claims = app(ClaimService::class);
    $small = $claims->create($staff, ['purpose' => 'small', 'lines' => [['claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '50']]], $staff);
    $big = $claims->create($staff, ['purpose' => 'big', 'lines' => [['claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '200']]], $staff);
    $claims->submit($small, $staff);
    $claims->submit($big, $staff);
    $claims->approve($small->fresh(), $app);
    expect($small->fresh()->status->value)->toBe('finance_payable')->and($small->approvals()->first()->delegation_id)->not->toBeNull();
    expect(fn () => $claims->approve($big->fresh(), $app))->toThrow(AuthorizationException::class);
})->group('M12-03', '§2.1');

it('claims above RM 5,000 need two different approvers', function (): void {
    $staff = userWithRoles('STF');
    $fin = userWithRoles('FIN');
    $claims = app(ClaimService::class);
    $c = $claims->create($staff, ['purpose' => 'Long trip', 'lines' => [['claim_type_id' => claimTypeId('mileage'), 'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '10000']]], $staff);
    $claims->submit($c, $staff);
    $claims->approve($c->fresh(), $fin);
    expect($c->fresh()->status->value)->toBe('pending_approval')->and($c->fresh()->current_level)->toBe(2);
    expect(fn () => $claims->approve($c->fresh(), $fin))->toThrow(MakerCheckerViolation::class, 'earlier level'); // same person twice
    expect(fn () => $claims->approve($c->fresh(), userWithRoles('FIN')))->toThrow(AuthorizationException::class); // level 2 is OWN only
    $claims->approve($c->fresh(), ownerUser());
    expect($c->fresh()->status->value)->toBe('finance_payable');
})->group('M12-03', 'M01-03');
