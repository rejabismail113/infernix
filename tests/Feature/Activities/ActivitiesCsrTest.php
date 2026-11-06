<?php

declare(strict_types=1);

use App\Kernel\Reports\ReportCatalog;
use App\Modules\Activities\Exceptions\ActivityRejected;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Services\ActivityService;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Csr\Exceptions\CsrRejected;
use App\Modules\Csr\Models\CsrEvidence;
use App\Modules\Csr\Models\CsrProgramme;
use App\Modules\Csr\Services\CsrService;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Services\ClaimService;
use App\Modules\Reporting\Services\ProjectFinancials;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS M07-01 … M07-03, M08-01 … M08-04, M02-05 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('M07-02: activity costs are linked, not copied — project margin counts them exactly once', function (): void {
    $project = makeProject();
    $activities = app(ActivityService::class);
    $trip = $activities->create(['type' => 'site_visit', 'title' => 'Site visit Penang', 'start_date' => '2026-05-04', 'end_date' => '2026-05-05', 'project_id' => $project->id, 'budget' => '1000.00'], ownerUser());
    expect($trip->client_id)->toBe($project->client_id);

    $hotel = postedTransaction(['project_id' => $project->id, 'lines' => [['description' => 'Hotel 1 night', 'amount' => '300.00', 'category_id' => categoryId('accommodation'), 'project_id' => $project->id]]]);
    $line = $hotel->lines()->firstOrFail();
    $marginBefore = app(ProjectFinancials::class)->for($project->id, ownerUser())['totals']['direct_costs'];
    $before = moneyRowCounts();

    $activities->linkCost($trip, 'transaction_line', $line->id, ownerUser());
    expect(moneyRowCounts())->toBe($before)
        ->and(app(ProjectFinancials::class)->for($project->id, ownerUser())['totals']['direct_costs'])->toBe($marginBefore)->toBe('300.00')
        ->and($activities->budgetVsActual($trip->id))->toMatchArray(['budget' => '1000.00', 'actual' => '300.00', 'variance' => '700.00']);

    // A cost belongs to one activity only.
    $other = $activities->create(['type' => 'trip', 'title' => 'Another trip', 'start_date' => '2026-05-06', 'end_date' => '2026-05-06'], ownerUser());
    expect(fn () => $activities->linkCost($other, 'transaction_line', $line->id, ownerUser()))->toThrow(PostingRejected::class, 'already linked');
    // Unlinking returns it; nothing is posted either way.
    $activities->unlinkCost($trip, 'transaction_line', $line->id, ownerUser());
    expect($activities->budgetVsActual($trip->id)['actual'])->toBe('0.00')->and(moneyRowCounts())->toBe($before);
    expect(fn () => $activities->unlinkCost($trip, 'transaction_line', $line->id, ownerUser()))->toThrow(ActivityRejected::class);
})->group('M07-02', 'M02-05', 'WF-02');

it('M07-01/M07-03: a trip groups mileage claims and hotel expenses; budget vs actual and the trip cost report read them', function (): void {
    $staffUser = userWithRoles('STF');
    $activities = app(ActivityService::class);
    $trip = $activities->create(['type' => 'trip', 'title' => 'Johor roadshow', 'start_date' => '2026-06-10', 'end_date' => '2026-06-11', 'budget' => '500.00'], ownerUser());
    $activities->saveItinerary($trip, [['item_date' => '2026-06-10', 'kind' => 'mileage', 'description' => 'KL → JB', 'estimated_cost' => '200.00'], ['item_date' => '2026-06-10', 'kind' => 'hotel', 'description' => 'Hotel', 'estimated_cost' => '250.00']], ownerUser());
    $activities->saveAttendees($trip, [['staff_profile_id' => makeStaff($staffUser)->id, 'role' => 'presenter'], ['name' => 'Guest speaker']], ownerUser());

    $claims = app(ClaimService::class);
    $claim = $claims->create($staffUser, ['purpose' => 'Roadshow', 'lines' => [['claim_type_id' => (int) ClaimType::query()->where('code', 'mileage')->value('id'), 'incurred_on' => '2026-06-10', 'description' => 'KL → JB', 'distance_km' => '350']]], $staffUser);
    $claims->submit($claim, $staffUser);
    $claims->approve($claim->fresh(), userWithRoles('FIN'));
    $activities->linkCost($trip, 'claim_line', $claim->lines()->firstOrFail()->id, ownerUser());
    $hotel = postedTransaction(['lines' => [['description' => 'Hotel JB', 'amount' => '180.00', 'category_id' => categoryId('accommodation')]]]);
    $activities->linkCost($trip, 'transaction_line', $hotel->lines()->firstOrFail()->id, ownerUser());

    $bva = $activities->budgetVsActual($trip->id);
    expect($bva['actual'])->toBe('390.00')->and($bva['by_kind'])->toMatchArray(['mileage' => '210.00', 'expense' => '180.00']);
    $report = app(ReportCatalog::class)->find('activities.trip-cost')?->run(['from' => '2026-06-01', 'to' => '2026-06-30'], ownerUser());
    expect($report['rows'][0])->toMatchArray(['mileage' => '210.00', 'other' => '180.00', 'actual' => '390.00', 'variance' => '110.00']);

    // The attendee (STF) can see the trip; another STF cannot.
    actingAsSatisfied($staffUser)->get(route('activities.activities.show', $trip->id))->assertOk();
    actingAsSatisfied(userWithRoles('STF'))->get(route('activities.activities.show', $trip->id))->assertForbidden();
    // Draft / unbooked records cannot be linked.
    $draft = $claims->create($staffUser, ['purpose' => 'x', 'lines' => [['claim_type_id' => (int) ClaimType::query()->where('code', 'mileage')->value('id'), 'incurred_on' => '2026-06-10', 'description' => 'x', 'distance_km' => '1']]], $staffUser);
    expect(fn () => $activities->linkCost($trip, 'claim_line', $draft->lines()->firstOrFail()->id, ownerUser()))->toThrow(PostingRejected::class, 'booked');
})->group('M07-01', 'M07-03');

it('M08-02/M08-03: CSR cash spend comes from finance via the programme activity; in-kind is kept out of cash totals', function (): void {
    $csr = app(CsrService::class);
    $p = $csr->create(['title' => 'School reading day', 'programme_date' => '2026-07-01', 'partner' => 'SK Taman Melati', 'beneficiary_group' => 'Year 4 pupils', 'target_count' => 80, 'budget' => '1500.00'], userWithRoles('HR'));
    expect($p->activity_id)->not->toBeNull();

    $books = postedTransaction(['lines' => [['description' => 'Story books', 'amount' => '500.00', 'category_id' => categoryId('csr')]]]);
    app(ActivityService::class)->linkCost(Activity::query()->findOrFail($p->activity_id), 'transaction_line', $books->lines()->firstOrFail()->id, ownerUser());
    $before = moneyRowCounts();
    $csr->addInKind($p, ['item' => 'Donated shelves', 'quantity' => '4', 'estimated_value' => '2000.00', 'source' => 'Partner company'], ownerUser());
    expect(moneyRowCounts())->toBe($before);

    expect($csr->summary($p->fresh()))->toMatchArray(['cash' => '500.00', 'in_kind' => '2000.00', 'budget' => '1500.00']);
    $spend = app(ReportCatalog::class)->find('csr.spend')?->run(['from' => '2026-01-01', 'to' => '2026-12-31']);
    expect($spend['totals'])->toBe(['cash' => '500.00', 'in_kind' => '2000.00'])->and($spend['rows'][0]['cash'])->toBe('500.00');
    // The ledger's CSR expense is the cash only — in-kind never reaches it.
    expect(balanceOf('5140'))->toBe('500.00');

    $pdf = actingAsSatisfied(ownerUser())->get(route('csr.annual-report', ['year' => 2026]));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    actingAsSatisfied(userWithRoles('STF'))->get(route('csr.annual-report', ['year' => 2026]))->assertForbidden();
})->group('M08-02', 'M08-03', 'M08-01');

it('M08-04: evidence photos are restricted; identifiable people need recorded consent before publishing', function (): void {
    $csr = app(CsrService::class);
    $hr = userWithRoles('HR');
    $p = $csr->create(['title' => 'Beach clean-up', 'programme_date' => '2026-08-01'], $hr);
    $e = $csr->addEvidence($p, Files::jpg('crowd.jpg'), true, false, null, $hr);
    expect(DB::table('documents')->where('id', $e->document_id)->value('classification'))->toBe('restricted');
    expect(fn () => $csr->setPublishable($e, true, $hr))->toThrow(CsrRejected::class, 'consent');
    expect(fn () => DB::transaction(fn () => DB::table('csr_evidence')->where('id', $e->id)->update(['publishable' => true])))->toThrow(QueryException::class);
    $csr->recordConsent($e, 'Consent forms CF-001..CF-040', $hr);
    $csr->setPublishable($e->fresh(), true, $hr);
    expect(CsrEvidence::query()->findOrFail($e->id)->publishable)->toBeTrue();

    // FIN sees the programme but not the restricted evidence; HR does.
    $fin = userWithRoles('FIN');
    actingAsSatisfied($fin)->get(route('csr.programmes.show', $p->id))->assertOk()->assertInertia(fn ($page) => $page->where('evidence', null));
    actingAsSatisfied($fin)->get(route('documents.download', $e->document_id))->assertForbidden();
    actingAsSatisfied($hr)->get(route('csr.programmes.show', $p->id))->assertOk()->assertInertia(fn ($page) => $page->has('evidence', 1));
    expect(CsrProgramme::query()->count())->toBe(1);
})->group('M08-04', 'NFR-02');
