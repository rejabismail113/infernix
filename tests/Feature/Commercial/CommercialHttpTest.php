<?php

declare(strict_types=1);

use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Models\DocumentTemplate;
use App\Modules\Commercial\Models\DocumentTemplateVersion;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Commercial\Models\Receipt;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS M03-01…M03-08, UAT-01, UAT-04, UAT-06, §5.1, NFR-02, M11-05 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('every commercial and projects page renders for the Owner', function (): void {
    $owner = ownerUser();
    $invoice = issuedInvoice('1500.00');
    $q = app(CommercialDocumentService::class)->createDraft(DocumentType::Quotation, ['client_id' => $invoice->client_id, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '10']]], $owner);
    $template = DocumentTemplate::query()->where('code', 'invoice')->firstOrFail();
    $pages = [
        route('commercial.quotations.index'), route('commercial.quotations.create'), route('commercial.quotations.show', $q->id), route('commercial.quotations.edit', $q->id),
        route('commercial.invoices.index'), route('commercial.invoices.create'), route('commercial.invoices.show', $invoice->id), route('commercial.credit-notes.index'),
        route('commercial.receipts.index'), route('commercial.receipts.create', ['invoice_id' => $invoice->id]), route('commercial.templates.index'),
        route('commercial.reports.show', 'quotation-register'), route('commercial.reports.show', 'invoice-register'), route('commercial.reports.show', 'issuance-history'),
        route('projects.clients.index'), route('projects.projects.index'), route('reports.dashboard.owner'), route('reports.dashboard.finance'), route('reports.exports.index'),
        route('notifications.index'), route('finance.reports.show', 'receivables-ageing'),
    ];
    foreach ($pages as $url) {
        expect(actingAsSatisfied($owner)->get($url)->status())->toBe(200, $url);
    }
    $preview = actingAsSatisfied($owner)->get(route('commercial.templates.preview', $template->versions()->firstOrFail()->id));
    expect($preview->status())->toBe(200)->and((string) $preview->headers->get('Content-Type'))->toBe('application/pdf')->and(substr((string) $preview->getContent(), 0, 4))->toBe('%PDF');
})->group('M03-01', 'M03-08', 'M11-01');

it('UAT-01 / M11-05: staff cannot reach invoices, quotations, receipts or their PDFs', function (): void {
    $invoice = issuedInvoice('900.00');
    $staff = userWithRoles('STF');
    foreach ([route('commercial.invoices.index'), route('commercial.invoices.show', $invoice->id), route('commercial.quotations.index'), route('commercial.receipts.index'),
        route('commercial.templates.index'), route('projects.clients.index'), route('commercial.reports.show', 'invoice-register')] as $url) {
        actingAsSatisfied($staff)->get($url)->assertForbidden();
    }
    actingAsSatisfied($staff)->get(route('documents.download', ['document' => $invoice->pdf_document_id]))->assertForbidden();
    actingAsSatisfied(userWithRoles('AUD'))->get(route('commercial.invoices.create'))->assertForbidden();
    actingAsSatisfied(userWithRoles('AUD'))->get(route('commercial.invoices.show', $invoice->id))->assertOk();
})->group('UAT-01', 'M11-05', 'NFR-02');

it('project managers see and edit only their own quotations', function (): void {
    $pm = userWithRoles('PM');
    $other = userWithRoles('PM');
    $client = makeClient();
    actingAsSatisfied($pm)->post(route('commercial.quotations.store'), ['client_id' => $client->id, 'title' => 'Mine', 'lines' => [['description' => 'x', 'quantity' => '2', 'unit_price' => '50.00']]])->assertRedirect();
    $mine = Quotation::query()->where('title', 'Mine')->firstOrFail();
    expect((string) $mine->total->getAmount())->toBe('100.00');
    actingAsSatisfied($other)->get(route('commercial.quotations.show', $mine->id))->assertForbidden();
    actingAsSatisfied($other)->put(route('commercial.quotations.update', $mine->id), ['client_id' => $client->id, 'lines' => [['description' => 'y', 'quantity' => '1', 'unit_price' => '1']]])->assertForbidden();
    actingAsSatisfied($other)->get(route('commercial.quotations.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('documents.data', 0));
    actingAsSatisfied($pm)->get(route('commercial.quotations.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('documents.data', 1));
    actingAsSatisfied($pm)->post(route('commercial.quotations.approve', $mine->id))->assertForbidden(); // PMs never approve
})->group('UAT-01', 'M03-01', 'NFR-02');

it('§5.1: issuing an invoice and posting a receipt are replay-safe with the same Idempotency-Key', function (): void {
    $svc = app(CommercialDocumentService::class);
    $maker = userWithRoles('FIN');
    $doc = $svc->createDraft(DocumentType::Invoice, ['client_id' => makeClient()->id, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '400']]], $maker);
    $svc->submit($doc, $maker);
    approveFully($doc);
    actingAsSatisfied($maker)->withHeader('Idempotency-Key', 'issue-1')->post(route('commercial.invoices.issue', $doc->id))->assertRedirect()->assertSessionHasNoErrors();
    actingAsSatisfied($maker)->withHeader('Idempotency-Key', 'issue-1')->post(route('commercial.invoices.issue', $doc->id))->assertRedirect()->assertSessionHasNoErrors();
    expect(Invoice::query()->whereNotNull('document_no')->count())->toBe(1)->and(balanceOf('accounts_receivable'))->toBe('400.00');

    $payload = ['receipt_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'amount' => '150.00', 'method' => 'duitnow', 'client_id' => $doc->client_id,
        'allocations' => [['invoice_id' => $doc->id, 'amount' => '150.00']]];
    $first = actingAsSatisfied($maker)->withHeader('Idempotency-Key', 'rc-1')->post(route('commercial.receipts.store'), $payload);
    $first->assertRedirect()->assertSessionHasNoErrors();
    actingAsSatisfied($maker)->withHeader('Idempotency-Key', 'rc-1')->post(route('commercial.receipts.store'), $payload)->assertRedirect($first->headers->get('Location'));
    expect(Receipt::query()->count())->toBe(1)->and((string) Invoice::query()->findOrFail($doc->id)->outstanding()->getAmount())->toBe('250.00');
})->group('§5.1', 'M03-04', 'UAT-04');

it('UAT-06 over HTTP: accept → project → invoice keeps client, scope and unique references', function (): void {
    $fin = userWithRoles('FIN');
    $client = makeClient(['name' => 'Agensi Contoh']);
    $svc = app(CommercialDocumentService::class);
    $q = $svc->createDraft(DocumentType::Quotation, ['client_id' => $client->id, 'title' => 'Workshop', 'lines' => [['description' => 'Workshop day', 'quantity' => '2', 'unit_price' => '2000']]], $fin);
    $svc->submit($q, $fin);
    approveFully($q);
    actingAsSatisfied($fin)->withHeader('Idempotency-Key', 'q-1')->post(route('commercial.quotations.issue', $q->id))->assertRedirect();
    actingAsSatisfied($fin)->post(route('commercial.quotations.respond', $q->id), ['accepted' => true, 'note' => 'PO 77'])->assertRedirect();
    actingAsSatisfied($fin)->post(route('commercial.quotations.convert-project', $q->id))->assertRedirect();
    $projectId = Project::query()->where('source_quotation_id', $q->id)->value('id');
    actingAsSatisfied($fin)->get(route('projects.projects.show', $projectId))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('project.client', 'Agensi Contoh'));
    actingAsSatisfied($fin)->post(route('commercial.quotations.convert-invoice', $q->id))->assertRedirect();
    $inv = Invoice::query()->where('source_id', $q->id)->firstOrFail();
    expect($inv->project_id)->toBe($projectId)->and($inv->client_id)->toBe($client->id)->and($inv->lines()->value('description'))->toBe('Workshop day');
    actingAsSatisfied($fin)->get(route('commercial.invoices.show', $inv->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('related.source_quotation.id', $q->id));
})->group('UAT-06', 'M03-02');

it('template settings are versioned (append-only); issued documents keep the version they used', function (): void {
    $invoice = issuedInvoice('100.00');
    $template = DocumentTemplate::query()->where('code', 'invoice')->firstOrFail();
    $v1 = $invoice->template_version_id;
    actingAsSatisfied(ownerUser())->post(route('commercial.templates.versions.store', $template->id), ['title' => 'TAX INVOICE', 'accent_color' => '#123456', 'show_bank_details' => true, 'notes' => 'SST registered'])->assertRedirect();
    expect(DocumentTemplateVersion::query()->where('document_template_id', $template->id)->max('version'))->toBe(2)
        ->and($invoice->fresh()->template_version_id)->toBe($v1)
        ->and(issuedInvoice('200.00')->template_version_id)->not->toBe($v1);
    actingAsSatisfied(userWithRoles('FIN'))->post(route('commercial.templates.versions.store', $template->id), ['title' => 'X', 'accent_color' => '#000000'])->assertForbidden();
})->group('M03-07', 'M03-08');
