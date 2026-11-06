<?php

declare(strict_types=1);

use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Notifications\NotificationService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Reports\ReportCatalog;
use App\Modules\Certifications\Exceptions\CertificationRejected;
use App\Modules\Certifications\Jobs\NotifyRenewals;
use App\Modules\Certifications\Models\Renewal;
use App\Modules\Certifications\Services\CertificationService;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Services\BillService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** @see SRS M09-01 … M09-04, G-05 */
afterEach(fn () => Carbon::setTestNow());

it('M09-02: renewal reminders fire once per 90/60/30-day threshold to HR/Owner and the holder', function (): void {
    Notification::fake();
    Carbon::setTestNow('2026-03-01 09:00:00');
    $holder = userWithRoles('STF');
    $staff = makeStaff($holder);
    $svc = app(CertificationService::class);
    $cert = $svc->saveCertification(null, ['holder_type' => 'staff', 'staff_profile_id' => $staff->id, 'name' => 'HRD Corp Certified Trainer', 'issuer' => 'HRD Corp', 'issue_date' => '2023-06-01', 'expiry_date' => '2026-05-25'], userWithRoles('HR'));
    expect(Renewal::query()->where('renewable_type', 'certification')->where('renewable_id', $cert->id)->where('status', 'open')->value('due_date')?->toDateString())->toBe('2026-05-25');

    $sent = fn (): int => DB::table('notification_dispatches')->where('dedupe_key', 'like', 'renewal:%')->count();
    (new NotifyRenewals)->handle(app(NotificationService::class), app(StaffDirectory::class), $svc); // 85 days → 90-day rule
    $first = $sent();
    expect($first)->toBeGreaterThan(0)->and(DB::table('notification_dispatches')->where('user_id', $holder->id)->where('dedupe_key', 'like', '%:L90')->exists())->toBeTrue();
    app()->call([new NotifyRenewals, 'handle']);
    expect($sent())->toBe($first); // same day: no duplicate

    Carbon::setTestNow('2026-03-30 09:00:00'); // 56 days → 60-day rule
    app()->call([new NotifyRenewals, 'handle']);
    expect(DB::table('notification_dispatches')->where('dedupe_key', 'like', '%:L60')->count())->toBeGreaterThan(0);
    Carbon::setTestNow('2026-04-30 09:00:00'); // 25 days → 30-day rule
    app()->call([new NotifyRenewals, 'handle']);
    expect(DB::table('notification_dispatches')->where('dedupe_key', 'like', '%:L30')->count())->toBeGreaterThan(0);
    // Reminders never create money.
    expect(DB::table('transactions')->count())->toBe(0)->and(DB::table('bills')->count())->toBe(0);
})->group('M09-02', 'G-05');

it('M09-03: certificates without expiry and annual memberships are supported', function (): void {
    $svc = app(CertificationService::class);
    $hr = userWithRoles('HR');
    $c = $svc->saveCertification(null, ['holder_type' => 'company', 'name' => 'SSM registration', 'issuer' => 'SSM', 'no_expiry' => true], $hr);
    expect($c->expiry_date)->toBeNull()->and(Renewal::query()->where('renewable_type', 'certification')->where('renewable_id', $c->id)->where('status', 'open')->exists())->toBeFalse();
    expect(fn () => $svc->saveCertification(null, ['holder_type' => 'company', 'name' => 'x', 'issuer' => 'y'], $hr))->toThrow(CertificationRejected::class);

    $m = $svc->saveMembership(null, ['holder_type' => 'company', 'organisation' => 'Malaysian Training Providers Assoc.', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'annual_fee' => '600.00'], $hr);
    $r = Renewal::query()->where('renewable_type', 'membership')->where('renewable_id', $m->id)->where('status', 'open')->firstOrFail();
    $svc->renew($r, '2026-12-15', '2027-12-31', 'Paid by transfer', $hr);
    expect($m->fresh()->period_end->toDateString())->toBe('2027-12-31')->and($m->fresh()->period_start->toDateString())->toBe('2027-01-01')
        ->and(Renewal::query()->where('renewable_type', 'membership')->where('renewable_id', $m->id)->where('status', 'open')->value('due_date')?->toDateString())->toBe('2027-12-31');
    $report = app(ReportCatalog::class)->find('certifications.expiring')?->run(['from' => '2026-01-01', 'to' => '2028-01-01']);
    expect(collect($report['rows'])->pluck('name')->all())->not->toContain('SSM registration');
})->group('M09-03', 'M09-01');

it('M09-04: the renewal fee is a link to the finance record that paid it, never a second expense', function (): void {
    $svc = app(CertificationService::class);
    $c = $svc->saveCertification(null, ['holder_type' => 'company', 'name' => 'ISO 9001', 'issuer' => 'SIRIM', 'expiry_date' => today()->addDays(40)->toDateString()], ownerUser());
    $r = Renewal::query()->where('renewable_type', 'certification')->where('renewable_id', $c->id)->firstOrFail();
    $bill = approvedVendorBill(makeVendor('SIRIM QAS'), [['description' => 'ISO 9001 surveillance audit', 'quantity' => '1', 'unit_price' => '3200.00', 'category_id' => categoryId('professional')]], 'SIR-2026-1');
    $before = moneyRowCounts();
    $svc->linkExpense($r, 'bill', $bill->id, ownerUser());
    expect(moneyRowCounts())->toBe($before)->and(balanceOf('5060'))->toBe('3200.00')
        ->and(app(FinanceRecords::class)->linkedTotals('renewal', [$r->id], 'renewal')[$r->id])->toBe('3200.00');
    $svc->renew($r, today()->toDateString(), today()->addYears(3)->toDateString(), null, ownerUser());
    $report = app(ReportCatalog::class)->find('certifications.expenditure')?->run(['from' => today()->startOfYear()->toDateString(), 'to' => today()->addYear()->toDateString()]);
    expect($report['totals']['paid'])->toBe('3200.00');
    // Draft finance records cannot be linked.
    $svc2 = app(BillService::class);
    $draft = $svc2->create(['vendor_id' => makeVendor()->id, 'vendor_invoice_no' => 'D-1', 'bill_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10']]], ownerUser());
    $open = Renewal::query()->where('renewable_type', 'certification')->where('renewable_id', $c->id)->where('status', 'open')->firstOrFail();
    expect(fn () => $svc->linkExpense($open, 'bill', $draft->id, ownerUser()))->toThrow(PostingRejected::class, 'not booked');
})->group('M09-04', 'M09-02');

it('M09-01: staff see only their own certifications', function (): void {
    $me = userWithRoles('STF');
    $svc = app(CertificationService::class);
    $mine = $svc->saveCertification(null, ['holder_type' => 'staff', 'staff_profile_id' => makeStaff($me)->id, 'name' => 'First Aid', 'issuer' => 'St John', 'expiry_date' => '2027-01-01'], ownerUser());
    $svc->saveCertification(null, ['holder_type' => 'staff', 'staff_profile_id' => makeStaff()->id, 'name' => 'Forklift', 'issuer' => 'NIOSH', 'expiry_date' => '2027-01-01'], ownerUser());
    actingAsSatisfied($me)->get(route('certifications.certifications.index'))->assertOk()
        ->assertInertia(fn ($p) => $p->has('certifications', 1)->where('certifications.0.id', $mine->id)->has('renewals', 1));
    actingAsSatisfied($me)->put(route('certifications.certifications.update', $mine->id + 1), ['holder_type' => 'company', 'name' => 'x', 'issuer' => 'y', 'no_expiry' => true])->assertForbidden();
})->group('M09-01', 'NFR-02');
