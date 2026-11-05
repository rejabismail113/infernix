<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Http\Controllers\ReportController;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\ClaimService;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS UAT-01, M01-03, M04-06, M06-02, NFR-02, §5.1 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

function httpClaim(User $staff, string $type = 'mileage', array $line = []): Claim
{
    $typeId = (int) ClaimType::query()->where('code', $type)->value('id');

    return app(ClaimService::class)->create($staff, ['purpose' => 'HTTP test', 'lines' => [[
        'claim_type_id' => $typeId, 'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '10', ...$line,
    ]]], $staff);
}

it('UAT-01: staff see only their own claims and get 403 on another staff claim', function (): void {
    $alice = userWithRoles('STF');
    $bob = userWithRoles('STF');
    $mine = httpClaim($alice);
    $theirs = httpClaim($bob);

    actingAsSatisfied($alice)->get(route('finance.claims.show', $theirs))->assertForbidden();
    actingAsSatisfied($alice)->get(route('finance.claims.edit', $theirs))->assertForbidden();
    actingAsSatisfied($alice)->post(route('finance.claims.submit', $theirs))->assertForbidden();
    actingAsSatisfied($alice)->get(route('finance.claims.show', $mine))->assertOk();
    actingAsSatisfied($alice)->get(route('finance.claims.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Finance/Claims/Index')->where('seesAll', false)
            ->has('claims.data', 1)->where('claims.data.0.id', $mine->id));
})->group('UAT-01', 'M01-03', 'NFR-02');

it('staff cannot reach bills, payments, journals, bank or finance reports', function (): void {
    $staff = userWithRoles('STF');
    foreach (['finance.bills.index', 'finance.payments.index', 'finance.journals.index', 'finance.bank.index', 'finance.reports.index', 'finance.accounts.index'] as $name) {
        actingAsSatisfied($staff)->get(route($name))->assertForbidden();
    }
    actingAsSatisfied($staff)->get(route('finance.reports.tax-pack', ['year' => now()->year]))->assertForbidden();
    actingAsSatisfied($staff)->get(route('finance.reports.export', 'trial-balance'))->assertForbidden();
})->group('UAT-01', 'M06-02', 'NFR-02');

it('masks the medical clinical note for Finance; HR and the claimant see it', function (): void {
    $staff = userWithRoles('STF');
    $claim = httpClaim($staff, 'medical', ['distance_km' => null, 'amount' => '80.00', 'clinical_note' => 'Clinical detail XYZ']);
    $hidden = fn (AssertableInertia $p) => $p->where('claim.lines.0.clinical_note', null)->where('claim.lines.0.clinical_note_masked', true);
    $visible = fn (AssertableInertia $p) => $p->where('claim.lines.0.clinical_note', 'Clinical detail XYZ')->where('claim.lines.0.clinical_note_masked', false);

    $fin = actingAsSatisfied(userWithRoles('FIN'))->get(route('finance.claims.show', $claim))->assertOk();
    $fin->assertInertia($hidden);
    expect($fin->getContent())->not->toContain('Clinical detail XYZ');
    actingAsSatisfied(userWithRoles('HR'))->get(route('finance.claims.show', $claim))->assertOk()->assertInertia($visible);
    actingAsSatisfied($staff)->get(route('finance.claims.show', $claim))->assertOk()->assertInertia($visible);
})->group('M01-03', 'M04-06', 'NFR-02');

it('replaying a payment with the same Idempotency-Key creates exactly one payment', function (): void {
    $staff = userWithRoles('STF');
    $claims = app(ClaimService::class);
    $claim = httpClaim($staff);
    $claims->submit($claim, $staff);
    $claims->approve($claim->fresh(), userWithRoles('FIN'));
    $payload = ['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'payee_type' => 'staff', 'payee_id' => $staff->id,
        'payee_name' => $staff->name, 'allocations' => [['target_type' => 'claim', 'target_id' => $claim->id, 'amount' => '6.00']]];
    $fin = userWithRoles('FIN');

    $first = actingAsSatisfied($fin)->withHeader('Idempotency-Key', 'pay-key-1')->post(route('finance.payments.store'), $payload);
    $first->assertRedirect()->assertSessionHasNoErrors();
    $second = actingAsSatisfied($fin)->withHeader('Idempotency-Key', 'pay-key-1')->post(route('finance.payments.store'), $payload);
    $second->assertRedirect($first->headers->get('Location'));
    expect(Payment::query()->count())->toBe(1);
})->group('§5.1', 'M01-05');

it('only Owner, Finance and Auditor may set tax treatment', function (): void {
    $t = postedTransaction();
    foreach (['PM', 'STF', 'HR'] as $role) {
        actingAsSatisfied(userWithRoles($role))->put(route('finance.transactions.tax-treatment', $t), ['tax_treatment' => 'non_deductible'])->assertForbidden();
    }
    actingAsSatisfied(userWithRoles('AUD'))->put(route('finance.transactions.tax-treatment', $t), ['tax_treatment' => 'non_deductible'])->assertRedirect();
    expect($t->fresh()->tax_treatment->value)->toBe('non_deductible');
})->group('M01-10', 'NFR-02');

it('locking a period requires a fresh authenticator re-confirmation', function (): void {
    $owner = ownerUser();
    $owner->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save();
    $period = FiscalPeriod::query()->orderBy('starts_on')->firstOrFail();

    $this->actingAs($owner)->withSession([EnsureMfa::SESSION_KEY => now()->toIso8601String(), RequireRecentAuth::SESSION_KEY => now()->toIso8601String()])
        ->post(route('admin.fiscal-periods.lock', $period), ['reason' => 'Month end'])->assertRedirectContains('/reauth');
    expect(session('reauth.require_mfa'))->toBeTrue();
    expect($period->fresh()->locked_at)->toBeNull();

    actingAsSatisfied($owner)->post(route('admin.fiscal-periods.lock', $period), ['reason' => 'Month end'])->assertRedirect();
    expect($period->fresh()->locked_at)->not->toBeNull();
})->group('M01-08', 'UAT-09', 'NFR-02');

it('every finance page renders for the Owner', function (): void {
    $owner = ownerUser();
    $t = postedTransaction();
    $period = FiscalPeriod::query()->orderBy('starts_on')->firstOrFail();
    $pages = [
        route('finance.overview'), route('finance.accounts.index'), route('finance.journals.index'), route('finance.journals.create'),
        route('finance.journals.show', $t->journal_id), route('finance.transactions.index'), route('finance.transactions.create'),
        route('finance.transactions.show', $t), route('finance.claims.index'), route('finance.claims.create'), route('finance.bills.index'),
        route('finance.bills.create'), route('finance.payments.index'), route('finance.payments.create'), route('finance.bank.index'),
        route('finance.bank.show', bank()), route('finance.bank.reconciliation', ['bank' => bank()->id, 'period' => $period->id]),
        route('finance.periods.index'), route('finance.periods.show', $period), route('finance.reports.index'), route('procurement.vendors.index'),
    ];
    foreach (array_keys(ReportController::REPORTS) as $r) {
        $pages[] = route('finance.reports.show', $r);
    }
    foreach ($pages as $url) {
        expect(actingAsSatisfied($owner)->get($url)->status())->toBe(200, $url);
    }
})->group('M01-01', 'M11-01');
