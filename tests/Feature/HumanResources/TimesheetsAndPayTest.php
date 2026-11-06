<?php

declare(strict_types=1);

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Money\Money;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\HumanResources\Exceptions\HrRejected;
use App\Modules\HumanResources\Models\Engagement;
use App\Modules\HumanResources\Services\PaymentBatchService;
use App\Modules\HumanResources\Services\StaffService;
use App\Modules\HumanResources\Services\TimesheetService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS UAT-07, WF-05, M04-02, M04-03, M04-04, M04-05, M04-06 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

function ts(): TimesheetService
{
    return app(TimesheetService::class);
}

it('UAT-07: staff cannot log time on a project they are not assigned to; the configured role exception is flagged', function (): void {
    $user = userWithRoles('STF');
    $staff = makeStaff($user);
    $assigned = makeProject(null, [$staff]);
    $other = makeProject();
    $t = ts()->create($staff, '2026-03-01', '2026-03-15', $user);

    expect(fn () => ts()->saveEntries($t, [['project_id' => $other->id, 'work_date' => '2026-03-02', 'quantity' => '4']], $user))->toThrow(HrRejected::class, 'not assigned');
    ts()->saveEntries($t, [['project_id' => $assigned->id, 'work_date' => '2026-03-02', 'quantity' => '4'], ['project_id' => $assigned->id, 'work_date' => '2026-03-03', 'quantity' => '3.5']], $user);
    expect($t->fresh()->total_quantity)->toBe('7.50');

    // Owner is in infernix.timesheets.unassigned_exception_roles (PLACEHOLDER): allowed, entry flagged.
    $owner = ownerUser();
    $t2 = ts()->create(makeStaff(), '2026-03-01', '2026-03-15', $owner);
    ts()->saveEntries($t2, [['project_id' => $other->id, 'work_date' => '2026-03-02', 'quantity' => '1']], $owner);
    expect($t2->entries()->first()->exception_granted)->toBeTrue()->and($t2->entries()->first()->exception_granted_by)->toBe($owner->id);
})->group('UAT-07', 'M04-02');

it('WF-05: payment uses the locked rate version; a new rate never changes an already-calculated timesheet or batch', function (): void {
    $pmUser = userWithRoles('PM');
    $user = userWithRoles('STF');
    $staff = makeStaff($user, 'contractor', '50.00');
    $project = makeProject($pmUser, [$staff]);
    $t = ts()->create($staff, '2026-03-01', '2026-03-31', $user);
    ts()->saveEntries($t, [['project_id' => $project->id, 'work_date' => '2026-03-10', 'quantity' => '10']], $user);
    ts()->submit($t->fresh(), $user);
    expect(fn () => ts()->verify($t->fresh(), $user, true))->toThrow(HrRejected::class, 'own timesheet');
    ts()->verify($t->fresh(), $pmUser, true);
    $fin = userWithRoles('FIN');
    ts()->calculate($t->fresh(), $fin);
    $t->refresh();
    $v1 = Engagement::query()->where('staff_profile_id', $staff->id)->where('version', 1)->firstOrFail();
    expect((string) $t->calculated_amount->getAmount())->toBe('500.00')->and($v1->locked_at)->not->toBeNull();

    // The locked version's rate can never change (DB) and a new version leaves the calculation untouched.
    expect(fn () => DB::transaction(fn () => DB::table('engagements')->where('id', $v1->id)->update(['rate_amount' => '80'])))->toThrow(QueryException::class, 'locked');
    expect(fn () => DB::transaction(fn () => DB::table('engagements')->where('id', $v1->id)->delete()))->toThrow(QueryException::class, 'locked');
    app(StaffService::class)->addEngagement($staff, ['rate_type' => 'hourly', 'rate_amount' => '80.00', 'effective_from' => '2026-03-15'], ownerUser());
    $batch = app(PaymentBatchService::class)->create([$t->id], '2026-03-31', 'voucher', $fin);
    expect((string) $t->fresh()->calculated_amount->getAmount())->toBe('500.00')->and((string) $batch->total_amount->getAmount())->toBe('500.00');
    expect(fn () => DB::transaction(fn () => DB::table('timesheets')->where('id', $t->id)->update(['calculated_amount' => '800'])))->toThrow(QueryException::class, 'locked rate');
})->group('WF-05', 'M04-03');

it('M04-04/M04-05: batch → contractor invoice required → maker-checker approval → accrual by project → paid with a voucher', function (): void {
    $pmUser = userWithRoles('PM');
    $user = userWithRoles('STF');
    $staff = makeStaff($user, 'contractor', '120.00', 'daily');
    $p1 = makeProject($pmUser, [$staff]);
    $p2 = makeProject($pmUser, [$staff]);
    $t = ts()->create($staff, '2026-04-01', '2026-04-30', $user);
    ts()->saveEntries($t, [['project_id' => $p1->id, 'work_date' => '2026-04-02', 'quantity' => '2'], ['project_id' => $p2->id, 'work_date' => '2026-04-03', 'quantity' => '1']], $user);
    ts()->submit($t->fresh(), $user);
    ts()->verify($t->fresh(), $pmUser, true);
    $fin = userWithRoles('FIN');
    ts()->calculate($t->fresh(), $fin);
    $svc = app(PaymentBatchService::class);
    $batch = $svc->create([$t->id], '2026-04-30', 'voucher', $fin);

    expect(fn () => $svc->submit($batch, $fin))->toThrow(HrRejected::class, 'contractor');
    $line = $batch->lines()->firstOrFail();
    app(DocumentStore::class)->attach(Files::pdf('contractor-invoice.pdf'), $fin, ['title' => 'Contractor invoice'], new LinkTarget('payment_batch_line', $line->id, 'invoice'));
    $svc->submit($batch->fresh(), $fin);
    expect(fn () => $svc->approve($batch->fresh(), $fin))->toThrow(MakerCheckerViolation::class);
    $svc->approve($batch->fresh(), userWithRoles('FIN'));
    $batch->refresh();
    expect($batch->status)->toBe('approved')->and(balanceOf('accrued_staff_payments'))->toBe('-360.00')->and(balanceOf('staff_costs_contract'))->toBe('360.00');
    $byProject = DB::table('journal_lines')->where('journal_id', $batch->accrual_journal_id)->whereNotNull('project_id')->pluck('debit', 'project_id')->map(fn ($v) => (string) Money::fromDb((string) $v)->getAmount());
    expect($byProject->all())->toBe([$p1->id => '240.00', $p2->id => '120.00']);

    $svc->markPaid($batch, bank()->id, '2026-05-02', $fin);
    $batch->refresh();
    expect($batch->status)->toBe('paid')->and($batch->voucher_no)->toStartWith('PV-')->and($batch->voucher_document_id)->not->toBeNull()
        ->and(balanceOf('accrued_staff_payments'))->toBe('0.00')->and($t->fresh()->status->value)->toBe('paid');
})->group('M04-04', 'M04-05', 'UAT-11');

it('employees need no contractor invoice; payroll handoff stores only masked bank numbers and the full file is audited', function (): void {
    $staff = makeStaff(null, 'employee', '30.00');
    app(StaffService::class)->setBank($staff, ['bank_name' => 'Maybank', 'account_no' => '514012345678', 'account_name' => 'NUR AISYAH'], ownerUser());
    $project = makeProject(null, [$staff]);
    $owner = ownerUser();
    $t = ts()->create($staff, '2026-04-01', '2026-04-30', $owner);
    ts()->saveEntries($t, [['project_id' => $project->id, 'work_date' => '2026-04-02', 'quantity' => '5']], $owner);
    ts()->submit($t->fresh(), $owner);
    ts()->verify($t->fresh(), $owner, true);
    ts()->calculate($t->fresh(), $owner);
    $svc = app(PaymentBatchService::class);
    $fin = userWithRoles('FIN');
    $b = $svc->create([$t->id], '2026-04-30', 'payroll_csv', $fin);
    $svc->submit($b, $fin);
    $svc->approve($b->fresh(), $owner);
    $svc->markPaid($b->fresh(), bank()->id, '2026-05-02', $fin);
    $b->refresh();
    $stored = (string) Storage::disk(config('infernix.documents.disk'))->get(Document::query()->findOrFail($b->handoff_document_id)->versions()->firstOrFail()->storage_key);
    expect($stored)->toContain('**** **** 5678')->not->toContain('514012345678');
    expect(collect($svc->handoffRows($b, true))->first()[5])->toBe('514012345678');
})->group('M04-04', 'M04-06');

it('M04-06: NRIC is encrypted at rest, masked by default and revealed only with an audited reason', function (): void {
    $staff = makeStaff();
    $raw = (string) DB::table('staff_profiles')->where('id', $staff->id)->value('id_number');
    expect($raw)->not->toContain('900101')->and($staff->maskedId())->toBe('******-**-5678')
        ->and(json_encode($staff->toArray()))->not->toContain('900101145678');
    $nric = app(StaffService::class)->revealIdentifier($staff, ownerUser(), 'Payroll registration');
    expect($nric)->toBe('900101145678')
        ->and(AuditEvent::query()->where('action', 'reveal')->where('entity_id', $staff->id)->where('reason', 'Payroll registration')->exists())->toBeTrue();
    expect(AuditEvent::query()->where('entity_id', $staff->id)->get()->map(fn ($e) => json_encode([$e->before, $e->after]))->implode(''))->not->toContain('900101145678');
})->group('M04-06', 'NFR-07');
