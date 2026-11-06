<?php

declare(strict_types=1);

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\Certificate;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\GeneratorService;
use App\Modules\Commercial\Services\ReceiptService;
use App\Modules\DocumentCentre\Models\DocumentShare;
use App\Modules\Finance\Services\BillService;
use App\Modules\Finance\Services\ClaimService;
use App\Modules\HumanResources\Services\PaymentBatchService;
use App\Modules\HumanResources\Services\TimesheetService;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Projects\Exceptions\ProjectRejected;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Projects\Services\TrainingService;
use App\Modules\Reporting\Services\ProjectFinancials;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Files;

/** @see SRS M02-01 … M02-07, M03-06, UAT-06 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('M02-05: contribution margin = net revenue − the sum of linked direct costs (overhead excluded)', function (): void {
    $owner = ownerUser();
    $staffUser = userWithRoles('STF');
    $staff = makeStaff($staffUser, 'contractor', '100.00');
    $project = makeProject(null, [$staff]);

    // Revenue: invoice tagged to the project, less a credit note.
    $invoice = issuedInvoice('8000.00', Client::query()->findOrFail($project->client_id), ['project_id' => $project->id]);
    $svc = app(CommercialDocumentService::class);
    $cn = $svc->draftCreditNote($invoice, $owner, [['description' => 'Discount', 'quantity' => '1', 'unit_price' => '500.00']]);
    $svc->submit($cn, $owner);
    approveFully($cn);
    $svc->issue($cn->fresh(), $owner);

    // Direct costs: an expense, a vendor bill, a staff claim and part-time pay — all tagged to the project.
    postedTransaction(['project_id' => $project->id, 'lines' => [['description' => 'Venue', 'amount' => '700.00', 'category_id' => categoryId('rental'), 'project_id' => $project->id]]]);
    $vendor = Vendor::query()->create(['company_id' => $owner->company_id, 'name' => 'Print Shop', 'status' => 'active']);
    $bills = app(BillService::class);
    $bill = $bills->create(['vendor_id' => $vendor->id, 'vendor_invoice_no' => 'PS-1', 'bill_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
        'lines' => [['description' => 'Printing', 'quantity' => '1', 'unit_price' => '300.00', 'project_id' => $project->id]]], userWithRoles('FIN'));
    $bills->submit($bill, $owner);
    $bills->approve($bill->fresh(), userWithRoles('FIN'));
    $claimant = userWithRoles('STF');
    $claims = app(ClaimService::class);
    $claim = $claims->create($claimant, ['purpose' => 'Trip', 'project_id' => $project->id, 'lines' => [['claim_type_id' => (int) ClaimType::query()->where('code', 'mileage')->value('id'),
        'incurred_on' => today()->toDateString(), 'description' => 'To venue', 'distance_km' => '100', 'project_id' => $project->id]]], $claimant);
    $claims->submit($claim, $claimant);
    $claims->approve($claim->fresh(), userWithRoles('FIN'));
    $t = app(TimesheetService::class)->create($staff, '2026-03-01', '2026-03-31', $staffUser);
    app(TimesheetService::class)->saveEntries($t, [['project_id' => $project->id, 'work_date' => '2026-03-05', 'quantity' => '8']], $staffUser);
    app(TimesheetService::class)->submit($t->fresh(), $staffUser);
    app(TimesheetService::class)->verify($t->fresh(), $owner, true);
    app(TimesheetService::class)->calculate($t->fresh(), $owner);
    $batch = app(PaymentBatchService::class)->create([$t->id], '2026-03-31', 'voucher', userWithRoles('FIN'));
    $line = $batch->lines()->firstOrFail();
    app(DocumentStore::class)->attach(Files::pdf(), $owner, ['title' => 'inv'], new LinkTarget('payment_batch_line', $line->id, 'invoice'));
    app(PaymentBatchService::class)->submit($batch->fresh(), userWithRoles('FIN'));
    app(PaymentBatchService::class)->approve($batch->fresh(), $owner);
    postedTransaction(); // overhead — NOT tagged to the project

    $f = app(ProjectFinancials::class)->for($project->id, $owner);
    $costs = ['700.00', '300.00', '60.00', '800.00']; // expense, bill, claim (100 km × 0.60), timesheet 8h × 100
    $sum = array_reduce($costs, fn ($c, $v) => $c->plus($v), BigDecimal::zero());
    expect($f['totals']['net_revenue'])->toBe('7500.00')
        ->and($f['totals']['direct_costs'])->toBe((string) $sum->toScale(2))->toBe('1860.00')
        ->and($f['totals']['contribution'])->toBe('5640.00')
        ->and($f['totals']['margin_percent'])->toBe('75.2')
        ->and(collect($f['by_source'])->where('type', 'expense')->pluck('amount', 'source')->all())->toEqual(['Direct expenses' => '700.00', 'Vendor bills' => '300.00', 'Staff claims' => '60.00', 'Part-time & contractor fees' => '800.00']);
})->group('M02-05', '§8.1');

it('M02-02 / UAT-06: lead → quotation → client PO → project → invoice → receipt chain keeps client and scope without re-keying', function (): void {
    $fin = userWithRoles('FIN');
    $client = makeClient(['name' => 'Jabatan Contoh']);
    $lead = app(ProjectService::class)->saveLead(['client_id' => $client->id, 'title' => 'AI literacy training', 'estimated_value' => '12000', 'engagement_type' => 'training'], $fin);
    $svc = app(CommercialDocumentService::class);
    $q = $svc->createDraft(DocumentType::Quotation, ['client_id' => $client->id, 'lead_id' => $lead->id, 'title' => 'AI literacy training',
        'lines' => [['description' => 'AI literacy (3 cohorts)', 'quantity' => '3', 'unit_price' => '4000']]], $fin);
    expect($lead->fresh()->quotation_id)->toBe($q->id)->and($lead->fresh()->stage)->toBe('proposal');
    $svc->submit($q, $fin);
    approveFully($q);
    $q = $svc->issue($q->fresh(), $fin);
    $svc->recordClientResponse($q, true, $fin, 'LOA received');
    $po = app(GeneratorService::class)->recordClientPo(['client_id' => $client->id, 'external_ref' => 'LOA/2026/77', 'po_date' => today()->toDateString(), 'amount' => '12000.00', 'quotation_id' => $q->id], $fin);
    $projectId = $svc->convertToProject($q->fresh(), $fin);
    $project = Project::query()->findOrFail($projectId);
    expect($project->lead_id)->toBe($lead->id)->and($lead->fresh()->stage)->toBe('won')->and($project->client_id)->toBe($client->id)->and($project->scope)->toContain('AI literacy');

    $inv = $svc->convertToInvoice($q->fresh(), $fin);
    $svc->submit($inv, $fin);
    approveFully($inv);
    $inv = $svc->issue($inv->fresh(), $fin);
    app(ReceiptService::class)->record(['receipt_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'amount' => '5000.00', 'method' => 'transfer', 'client_id' => $client->id,
        'allocations' => [['invoice_id' => $inv->id, 'amount' => '5000.00']]], $fin);

    $chain = app(ProjectFinancials::class)->for($projectId, ownerUser())['chain'];
    expect($chain['lead']['id'])->toBe($lead->id)
        ->and(collect($chain['quotations'])->pluck('id')->all())->toBe([$q->id])
        ->and(collect($chain['client_pos'])->pluck('external_ref')->all())->toBe(['LOA/2026/77'])
        ->and(collect($chain['invoices'])->pluck('id')->all())->toBe([$inv->id])
        ->and(collect($chain['receipts'])->pluck('amount')->all())->toBe(['5000.00'])
        ->and($po->related_document_id)->toBe($q->id);
    expect(fn () => app(GeneratorService::class)->recordClientPo(['client_id' => $client->id, 'external_ref' => 'loa/2026/77', 'po_date' => today()->toDateString(), 'amount' => '1'], $fin))
        ->toThrow(CommercialRejected::class, 'already recorded');
})->group('M02-02', 'UAT-06', 'M03-05');

it('M02-07: a PM sees only assigned projects — 403 on others, no leakage via lists, documents or document search', function (): void {
    $pm = userWithRoles('PM');
    $mine = makeProject($pm);
    $theirs = makeProject(userWithRoles('PM'));
    $doc = storeDocument(ownerUser(), ['title' => 'Confidential contract for other project'], new LinkTarget('project', $theirs->id, 'contract'));
    $myDoc = storeDocument(ownerUser(), ['title' => 'Contract for my project'], new LinkTarget('project', $mine->id, 'contract'));

    actingAsSatisfied($pm)->get(route('projects.projects.show', $theirs->id))->assertForbidden();
    actingAsSatisfied($pm)->get(route('projects.projects.show', $mine->id))->assertOk();
    actingAsSatisfied($pm)->get(route('reports.projects.financials', $theirs->id))->assertForbidden();
    actingAsSatisfied($pm)->get(route('projects.projects.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('projects', 1)->where('projects.0.id', $mine->id));
    actingAsSatisfied($pm)->get(route('documents.show', $doc->id))->assertForbidden();
    actingAsSatisfied($pm)->get(route('documents.show', $myDoc->id))->assertOk();
    $search = actingAsSatisfied($pm)->get(route('documents.index', ['q' => 'contract']));
    $titles = collect($search->viewData('page')['props']['documents']['data'])->pluck('title')->all();
    expect($titles)->toContain('Contract for my project')->not->toContain('Confidential contract for other project');
    expect(fn () => app(ProjectFinancials::class)->for($theirs->id, $pm))->toThrow(AuthorizationException::class);

    // Explicitly shared documents are the exception (M02-07).
    DocumentShare::query()->create(['document_id' => $doc->id, 'user_id' => $pm->id, 'shared_by' => ownerUser()->id, 'expires_at' => now()->addDay()]);
    actingAsSatisfied($pm)->get(route('documents.show', $doc->id))->assertOk();
})->group('M02-07', 'UAT-01', 'NFR-02');

it('M02-03/M02-04: milestones track blockers; delivery status is separate from payment status; closing needs the checklist', function (): void {
    $p = makeProject();
    $svc = app(ProjectService::class);
    expect(fn () => $svc->saveMilestone($p, ['title' => 'Design', 'status' => 'blocked'], ownerUser()))->toThrow(ProjectRejected::class, 'blocker');
    $m = $svc->saveMilestone($p, ['title' => 'Design', 'status' => 'blocked', 'blocker' => 'Awaiting client data', 'due_on' => today()->subDay()->toDateString()], ownerUser());
    expect($m->isOverdue())->toBeTrue();
    $svc->setDeliveryStatus($p, 'completed', ownerUser());
    expect($p->fresh()->status_delivery)->toBe('completed')->and($p->fresh()->status_payment)->toBe('unbilled');
    expect(fn () => $svc->setDeliveryStatus($p->fresh(), 'closed', ownerUser()))->toThrow(ProjectRejected::class, 'checklist');
    $svc->saveCloseout($p->fresh(), array_fill_keys(array_keys(Project::CLOSEOUT_ITEMS), true), ownerUser());
    $svc->setDeliveryStatus($p->fresh(), 'closed', ownerUser());
    expect($p->fresh()->status_delivery)->toBe('closed');
    $lost = $svc->saveLead(['prospect_name' => 'Startup X', 'title' => 'Website'], ownerUser());
    expect(fn () => $svc->moveLead($lost, 'lost', ownerUser()))->toThrow(ProjectRejected::class, 'why');
    expect($svc->moveLead($lost, 'lost', ownerUser(), 'Budget')->stage)->toBe('lost');
})->group('M02-03', 'M02-04', 'M02-02');

it('M02-06 / M03-06: training attendance → certificates for consenting attendees, verifiable publicly without personal data', function (): void {
    $p = makeProject();
    $trainer = makeStaff();
    $svc = app(TrainingService::class);
    $s = $svc->saveSession($p, ['course_title' => 'Data Analytics Bootcamp', 'session_date' => '2026-05-10', 'trainer_staff_id' => $trainer->id, 'venue' => 'Cyberjaya'], ownerUser());
    $svc->saveParticipants($s, [
        ['name' => 'Ali bin Abu', 'organisation' => 'Agency A', 'email' => 'ali@example.com', 'consent_given' => true, 'attended' => true, 'feedback_score' => '4.5'],
        ['name' => 'Siti binti Omar', 'organisation' => 'Agency A', 'consent_given' => false, 'attended' => true, 'feedback_score' => '4'],
        ['name' => 'Chong Wei', 'organisation' => 'Agency B', 'consent_given' => true, 'attended' => false],
    ], ownerUser());
    expect($s->fresh()->feedback_score)->toBe('4.25');
    expect(fn () => $svc->issueCertificates($s->fresh(), ownerUser()))->toThrow(ProjectRejected::class, 'completed');
    $svc->saveSession($p, ['course_title' => 'Data Analytics Bootcamp', 'session_date' => '2026-05-10', 'status' => 'completed'], ownerUser(), $s);
    expect($svc->issueCertificates($s->fresh(), ownerUser()))->toBe(1)->and($svc->issueCertificates($s->fresh(), ownerUser()))->toBe(0);

    $cert = Certificate::query()->firstOrFail();
    expect($cert->document_no)->toMatch('/^CERT-\d{4}-0001$/')->and($cert->pdf_document_id)->not->toBeNull()->and($cert->counterparty_snapshot['name'])->toBe('Ali bin Abu');
    $page = $this->get(route('verify.certificate', $cert->verification_token))->assertOk();
    expect($page->getContent())->toContain('Valid certificate')->toContain($cert->document_no)->toContain('Data Analytics Bootcamp')
        ->not->toContain('Ali bin Abu')->not->toContain('Agency A');
    $this->get(route('verify.certificate', str_repeat('a', 40)))->assertNotFound();
    app(CommercialDocumentService::class)->void($cert, ownerUser(), 'Issued in error');
    expect($this->get(route('verify.certificate', $cert->verification_token))->getContent())->toContain('revoked');
    expect((string) DB::table('training_participants')->where('name', 'Ali bin Abu')->value('email'))->not->toContain('ali@example.com');
})->group('M02-06', 'M03-06', 'NFR-07');
