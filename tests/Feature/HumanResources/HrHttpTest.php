<?php

declare(strict_types=1);

use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Services\GeneratorService;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\HumanResources\Models\Timesheet;
use App\Modules\HumanResources\Services\StaffService;
use App\Modules\HumanResources\Services\TimesheetService;
use App\Modules\Projects\Services\TrainingService;
use App\Modules\Reporting\Models\Export;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS M04-02, M04-06, UAT-07, M11-05, NFR-02 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('M04-06: NRIC and bank numbers are masked in list, detail and export for FIN, PM and STF; reveal is HR/Owner only', function (): void {
    $user = userWithRoles('STF');
    $staff = makeStaff($user);
    app(StaffService::class)->setBank($staff, ['bank_name' => 'CIMB', 'account_no' => '800012345678', 'account_name' => 'X'], ownerUser());

    foreach (['FIN', 'PM', 'HR', 'OWN'] as $role) {
        $viewer = $role === 'OWN' ? ownerUser() : userWithRoles($role);
        $list = actingAsSatisfied($viewer)->get(route('hr.staff.index'))->assertOk();
        $detail = actingAsSatisfied($viewer)->get(route('hr.staff.show', $staff->id))->assertOk();
        foreach ([$list, $detail] as $res) {
            expect($res->getContent())->not->toContain('900101145678')->not->toContain('800012345678')->toContain('******-**-5678');
        }
    }
    // The staff member sees their own profile — still masked — and nobody else's.
    actingAsSatisfied($user)->get(route('hr.staff.show', $staff->id))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('staff.id_masked', '******-**-5678'));
    actingAsSatisfied($user)->get(route('hr.staff.show', makeStaff()->id))->assertForbidden();
    actingAsSatisfied($user)->get(route('hr.staff.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('staff', 1));

    // Export (roster) is masked too.
    $fin = userWithRoles('FIN');
    actingAsSatisfied($fin)->post(route('reports.exports.store'), ['report' => 'hr.roster', 'format' => 'csv'])->assertRedirect();
    $csv = (string) Storage::disk(config('infernix.documents.disk'))->get(Document::query()->findOrFail(Export::query()->latest('id')->value('document_id'))->versions()->firstOrFail()->storage_key);
    expect($csv)->toContain('******-**-5678')->not->toContain('900101145678');

    // Reveal: FIN/PM/STF forbidden; HR allowed, audited, needs a reason.
    foreach (['FIN', 'PM', 'STF'] as $role) {
        actingAsSatisfied(userWithRoles($role))->postJson(route('hr.staff.reveal-id', $staff->id), ['reason' => 'curious'])->assertForbidden();
    }
    actingAsSatisfied(userWithRoles('HR'))->postJson(route('hr.staff.reveal-id', $staff->id), [])->assertUnprocessable();
    actingAsSatisfied(userWithRoles('HR'))->postJson(route('hr.staff.reveal-id', $staff->id), ['reason' => 'EPF registration'])->assertOk()->assertJson(['value' => '900101145678']);
    actingAsSatisfied($fin)->postJson(route('hr.staff.reveal-bank', $staff->id), ['reason' => 'Pay contractor'])->assertOk()->assertJsonPath('value.account_no', '800012345678');
    actingAsSatisfied(userWithRoles('PM'))->postJson(route('hr.staff.reveal-bank', $staff->id), ['reason' => 'x'])->assertForbidden();
})->group('M04-06', 'NFR-07', 'M11-05');

it('UAT-07 over HTTP and M04-02 self-service: own timesheets only; unassigned project rejected', function (): void {
    $user = userWithRoles('STF');
    $staff = makeStaff($user);
    $assigned = makeProject(null, [$staff]);
    $other = makeProject();
    actingAsSatisfied($user)->post(route('hr.timesheets.store'), ['period_start' => '2026-06-01', 'period_end' => '2026-06-30'])->assertRedirect();
    $t = Timesheet::query()->where('staff_profile_id', $staff->id)->firstOrFail();

    actingAsSatisfied($user)->put(route('hr.timesheets.entries', $t->id), ['entries' => [['project_id' => $other->id, 'work_date' => '2026-06-02', 'quantity' => '3']]])->assertSessionHasErrors('general');
    expectSessionErrorContains('general', 'not assigned');
    expect($t->entries()->count())->toBe(0);
    actingAsSatisfied($user)->put(route('hr.timesheets.entries', $t->id), ['entries' => [['project_id' => $assigned->id, 'work_date' => '2026-06-02', 'quantity' => '3']]])->assertSessionHasNoErrors();
    expect($t->fresh()->total_quantity)->toBe('3.00');

    $colleague = userWithRoles('STF');
    $theirs = app(TimesheetService::class)->create(makeStaff($colleague), '2026-06-01', '2026-06-30', $colleague);
    actingAsSatisfied($user)->get(route('hr.timesheets.show', $theirs->id))->assertForbidden();
    actingAsSatisfied($user)->put(route('hr.timesheets.entries', $theirs->id), ['entries' => []])->assertForbidden();
    actingAsSatisfied($user)->post(route('hr.timesheets.verify', $t->id), ['approve' => true])->assertForbidden();
    actingAsSatisfied($user)->get(route('hr.batches.index'))->assertForbidden();
    actingAsSatisfied($user)->get(route('finance.claims.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('seesAll', false));
})->group('UAT-07', 'M04-02', 'NFR-02');

it('every P2 page renders for the Owner', function (): void {
    $owner = ownerUser();
    $staff = makeStaff();
    $project = makeProject(null, [$staff]);
    $session = app(TrainingService::class)->saveSession($project, ['course_title' => 'Course', 'session_date' => '2026-05-01'], $owner);
    $t = app(TimesheetService::class)->create($staff, '2026-05-01', '2026-05-31', $owner);
    $memo = app(GeneratorService::class)->createDraft(DocumentType::Memo, ['staff_profile_id' => $staff->id, 'subject' => 'Hello', 'body' => 'Body'], $owner);
    $pages = [
        route('projects.leads.index'), route('projects.projects.index'), route('projects.projects.show', $project->id), route('projects.sessions.show', $session->id),
        route('reports.projects.financials', $project->id), route('reports.index'), route('reports.view', 'projects.pipeline'), route('reports.view', 'projects.contribution'),
        route('reports.view', 'projects.overdue-milestones'), route('reports.view', 'projects.training-register'), route('reports.view', 'hr.roster'),
        route('reports.view', 'hr.timesheet-summary'), route('reports.view', 'hr.payment-history'),
        route('hr.staff.index'), route('hr.staff.show', $staff->id), route('hr.timesheets.index'), route('hr.timesheets.show', $t->id), route('hr.batches.index'), route('hr.memos.index'),
        route('commercial.client-pos.index'), route('commercial.vendor-pos.index'), route('commercial.vendor-pos.create'),
        route('commercial.generated.index'), route('commercial.generated.create', ['type' => 'memo']), route('commercial.generated.create', ['type' => 'appointment_letter']),
        route('commercial.generated.show', $memo->id), route('commercial.generated.edit', $memo->id),
    ];
    foreach ($pages as $url) {
        expect(actingAsSatisfied($owner)->get($url)->status())->toBe(200, $url);
    }
})->group('M02-01', 'M04-01', 'M03-06');
