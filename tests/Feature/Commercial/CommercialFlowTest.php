<?php

declare(strict_types=1);

use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\StateMachine\IllegalTransition;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\Company;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Commercial\Models\Receipt;
use App\Modules\Commercial\Models\ReceiptDocument;
use App\Modules\Commercial\Models\VoucherDocument;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\ReceiptService;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Services\ClaimService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** @see SRS UAT-04, UAT-06, M03-01…M03-08, WF-04, UAT-11 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

function svc(): CommercialDocumentService
{
    return app(CommercialDocumentService::class);
}

function receiptFor(Invoice $invoice, string $amount, ?string $allocate = null): Receipt
{
    return app(ReceiptService::class)->record([
        'receipt_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'amount' => $amount, 'method' => 'transfer', 'reference' => 'IBG 778',
        'client_id' => $invoice->client_id, 'allocations' => [['invoice_id' => $invoice->id, 'amount' => $allocate ?? $amount]],
    ], userWithRoles('FIN'));
}

it('UAT-04: RM10,000 invoice paid RM3,000 → outstanding RM7,000, receipt linked, unique PDF numbers', function (): void {
    $invoice = issuedInvoice('10000.00');
    expect($invoice->document_no)->toMatch('/^INV-\d{4}-\d{4}$/')
        ->and($invoice->settlement_status->value)->toBe('unpaid')
        ->and(balanceOf('accounts_receivable'))->toBe('10000.00')->and(balanceOf('revenue_project'))->toBe('-10000.00');

    $receipt = receiptFor($invoice, '3000.00');
    $invoice->refresh();
    expect((string) $invoice->outstanding()->getAmount())->toBe('7000.00')
        ->and($invoice->settlement_status->value)->toBe('partially_paid')
        ->and($receipt->receipt_no)->toMatch('/^RC-\d{4}-\d{4}$/')
        ->and($receipt->allocations()->first()->invoice_id)->toBe($invoice->id)
        ->and(balanceOf('accounts_receivable'))->toBe('7000.00')->and(balanceOf('1010'))->toBe('3000.00')
        ->and($invoice->pdf_document_id)->not->toBeNull()->and($receipt->pdf_document_id)->not->toBeNull();

    $numbers = DB::table('commercial_documents')->whereNotNull('document_no')->pluck('document_no');
    expect($numbers->unique()->count())->toBe($numbers->count())
        ->and(Document::query()->whereKey($invoice->pdf_document_id)->value('frozen'))->toBeTrue();

    // A second receipt cannot over-allocate (service and database both refuse).
    expect(fn () => receiptFor($invoice->fresh(), '8000.00'))->toThrow(CommercialRejected::class, 'exceeds the outstanding');
    expect(fn () => DB::transaction(fn () => DB::table('receipt_allocations')->insert(['receipt_id' => $receipt->id + 0, 'invoice_id' => $invoice->id, 'amount' => '7000.0001'])))
        ->toThrow(QueryException::class);
    receiptFor($invoice->fresh(), '7000.00');
    expect($invoice->fresh()->settlement_status->value)->toBe('paid')->and(balanceOf('accounts_receivable'))->toBe('0.00');
})->group('UAT-04', 'M03-03', 'M03-04', 'WF-04');

it('UAT-06: quotation → accepted → project → invoice keeps client, scope and source links, with unique refs and audit', function (): void {
    $client = makeClient(['name' => 'Kementerian Contoh']);
    $pm = userWithRoles('FIN');
    $q = svc()->createDraft(DocumentType::Quotation, ['client_id' => $client->id, 'title' => 'Digital skills programme', 'valid_until' => today()->addDays(30)->toDateString(), 'lines' => [
        ['description' => 'Training day (20 pax)', 'quantity' => '3', 'unit' => 'day', 'unit_price' => '4500.00', 'discount' => '500.00'],
        ['description' => 'Course materials', 'quantity' => '20', 'unit' => 'set', 'unit_price' => '25.00'],
    ]], $pm);
    expect((string) $q->total->getAmount())->toBe('13500.00'); // 3×4500 − 500 + 20×25
    svc()->submit($q, $pm);
    approveFully($q);
    $q = svc()->issue($q->fresh(), $pm);
    expect($q->document_no)->toMatch('/^QT-\d{4}-0001$/')->and($q->counterparty_snapshot['name'])->toBe('Kementerian Contoh');

    expect(fn () => svc()->convertToProject($q, $pm))->toThrow(CommercialRejected::class, 'accepted');
    /** @var Quotation $q */
    $q = svc()->recordClientResponse($q, true, $pm, 'PO received');
    $projectId = svc()->convertToProject($q, $pm);
    expect(svc()->convertToProject($q->fresh(), $pm))->toBe($projectId); // idempotent

    $project = Project::query()->findOrFail($projectId);
    expect($project->client_id)->toBe($client->id)->and($project->source_quotation_id)->toBe($q->id)
        ->and((string) $project->contract_value->getAmount())->toBe('13500.00')->and($project->scope)->toContain('Training day');

    $inv = svc()->convertToInvoice($q->fresh(), $pm);
    expect($inv->client_id)->toBe($client->id)->and($inv->project_id)->toBe($projectId)->and($inv->source_type)->toBe('quotation')->and($inv->source_id)->toBe($q->id)
        ->and($inv->lines()->count())->toBe(2)->and($inv->lines()->first()->source_line_id)->toBe($q->lines()->first()->id)
        ->and((string) $inv->total->getAmount())->toBe('13500.00');
    svc()->submit($inv, $pm);
    approveFully($inv);
    $inv = svc()->issue($inv->fresh(), $pm);
    expect($inv->document_no)->toStartWith('INV-')->and($inv->lines_snapshot['extra']['quotation_no'])->toBe($q->document_no)
        ->and($project->fresh()->status_payment)->toBe('billed');

    $actions = AuditEvent::query()->where('entity_type', Quotation::class)->where('entity_id', $q->id)->pluck('action')->all();
    expect($actions)->toContain('create', 'issue', 'accept', 'convert');
})->group('UAT-06', 'M03-01', 'M03-02', 'WF-04', 'G-06');

it('M03-04: a receipt only exists with its posted journal and a PDF is never produced for an unposted receipt', function (): void {
    $invoice = issuedInvoice('500.00');
    expect(fn () => DB::transaction(fn () => DB::table('receipts')->insert([
        'company_id' => Company::current()->id, 'receipt_no' => 'RC-FAKE-1', 'receipt_date' => today(), 'bank_account_id' => bank()->id, 'amount' => '100', 'method' => 'cash',
        'payer_name' => 'x', 'status' => 'posted', 'created_by' => ownerUser()->id,
    ])))->toThrow(QueryException::class, 'journal_id');

    $receipt = receiptFor($invoice, '500.00');
    expect(Journal::query()->whereKey($receipt->journal_id)->value('status')->value)->toBe('posted');
    app(ReceiptService::class)->void($receipt, ownerUser(), 'Cheque bounced');
    $receipt->refresh();
    expect($receipt->status)->toBe('void')->and($receipt->receipt_no)->toStartWith('RC-')
        ->and(Journal::query()->whereKey($receipt->journal_id)->value('status')->value)->toBe('reversed')
        ->and($invoice->fresh()->settlement_status->value)->toBe('unpaid')
        ->and(ReceiptDocument::query()->where('source_id', $receipt->id)->firstOrFail()->status)->toBe(DocumentStatus::Void);
    expect(fn () => app(ReceiptService::class)->storePdf($receipt, ownerUser()))->toThrow(CommercialRejected::class, 'posted receipt');
})->group('M03-04', 'UAT-04');

it('void keeps the number, reverses the posting and stores a VOID copy; voided numbers are never reused', function (): void {
    $invoice = issuedInvoice('1200.00');
    $number = $invoice->document_no;
    $voided = svc()->void($invoice, ownerUser(), 'Raised to the wrong client');
    expect($voided->status)->toBe(DocumentStatus::Void)->and($voided->document_no)->toBe($number)
        ->and($voided->void_pdf_document_id)->not->toBeNull()->and($voided->pdf_document_id)->not->toBe($voided->void_pdf_document_id)
        ->and(Journal::query()->whereKey($voided->journal_id)->value('status')->value)->toBe('reversed')
        ->and(balanceOf('accounts_receivable'))->toBe('0.00');
    $next = issuedInvoice('300.00');
    expect($next->document_no)->not->toBe($number);
    expect(fn () => DB::transaction(fn () => DB::table('commercial_documents')->where('id', $voided->id)->delete()))->toThrow(QueryException::class, 'never deleted');

    $paid = issuedInvoice('800.00');
    receiptFor($paid, '100.00');
    expect(fn () => svc()->void($paid->fresh(), ownerUser(), 'oops'))->toThrow(CommercialRejected::class, 'credit note');
})->group('M03-07', '§6.3', 'M03-04');

it('issued snapshot is immutable: profile changes do not alter it and the database refuses edits', function (): void {
    $invoice = issuedInvoice('2500.00');
    $snapshot = $invoice->issuer_profile_snapshot;
    Company::current()->forceFill(['name' => 'Renamed Enterprise', 'signatory_name' => 'Someone Else'])->save();
    expect($invoice->fresh()->issuer_profile_snapshot)->toBe($snapshot)->and($snapshot['name'])->not->toBe('Renamed Enterprise');

    foreach (["issuer_profile_snapshot = '{}'::jsonb", 'total = 1', "document_no = 'INV-HACK'", "issue_date = '2020-01-01'"] as $set) {
        expect(fn () => DB::transaction(fn () => DB::statement("UPDATE commercial_documents SET {$set} WHERE id = ?", [$invoice->id])))->toThrow(QueryException::class, 'immutable');
    }
    expect(fn () => DB::transaction(fn () => DB::table('document_lines')->where('commercial_document_id', $invoice->id)->update(['description' => 'changed'])))->toThrow(QueryException::class, 'cannot change');
    expect(fn () => DB::transaction(fn () => DB::table('commercial_documents')->where('id', $invoice->id)->update(['pdf_document_id' => null])))->toThrow(QueryException::class, 'frozen');
    expect(fn () => DB::transaction(fn () => DB::table('document_template_versions')->update(['view_name' => 'x'])))->toThrow(QueryException::class, 'append-only');
})->group('M03-07', 'M03-08', '§6.3');

it('UAT-11: the preparer cannot approve their own invoice; an Owner override needs a reason', function (): void {
    $fin = userWithRoles('FIN');
    $inv = svc()->createDraft(DocumentType::Invoice, ['client_id' => makeClient()->id, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '100']]], $fin);
    svc()->submit($inv, $fin);
    expect(fn () => svc()->approve($inv->fresh(), $fin))->toThrow(MakerCheckerViolation::class);

    $owner = ownerUser();
    $own = svc()->createDraft(DocumentType::Invoice, ['client_id' => makeClient()->id, 'lines' => [['description' => 'x', 'quantity' => '1', 'unit_price' => '100']]], $owner);
    svc()->submit($own, $owner);
    expect(fn () => svc()->approve($own->fresh(), $owner))->toThrow(MakerCheckerViolation::class);
    svc()->approve($own->fresh(), $owner, 'Sole proprietor, no second approver');
    expect($own->fresh()->status)->toBe(DocumentStatus::Approved)
        ->and(AuditEvent::query()->where('action', 'owner_override')->where('entity_id', $own->id)->exists())->toBeTrue();
})->group('UAT-11', '§2.1');

it('credit notes need approval, post the reverse of revenue and reduce the outstanding balance', function (): void {
    $invoice = issuedInvoice('1000.00');
    $fin = userWithRoles('FIN');
    $cn = svc()->draftCreditNote($invoice, $fin, [['description' => 'Session cancelled', 'quantity' => '1', 'unit_price' => '400.00']]);
    svc()->submit($cn, $fin);
    expect(fn () => svc()->issue($cn->fresh(), $fin))->toThrow(IllegalTransition::class);
    svc()->approve($cn->fresh(), ownerUser());
    $cn = svc()->issue($cn->fresh(), $fin);
    expect($cn->document_no)->toStartWith('CN-')->and((string) $invoice->fresh()->outstanding()->getAmount())->toBe('600.00')
        ->and(balanceOf('accounts_receivable'))->toBe('600.00')->and(balanceOf('revenue_project'))->toBe('-600.00');

    $tooMuch = svc()->draftCreditNote($invoice->fresh(), $fin, [['description' => 'More', 'quantity' => '1', 'unit_price' => '700.00']]);
    expect(fn () => svc()->submit($tooMuch, $fin))->toThrow(CommercialRejected::class, 'exceeds the outstanding');

    svc()->void($cn, ownerUser(), 'Issued in error');
    expect((string) $invoice->fresh()->outstanding()->getAmount())->toBe('1000.00')->and(balanceOf('accounts_receivable'))->toBe('1000.00');
})->group('M03-07', 'M03-03');

it('a quotation revision keeps the number, bumps the revision and supersedes the previous one when issued', function (): void {
    $fin = userWithRoles('FIN');
    $q = svc()->createDraft(DocumentType::Quotation, ['client_id' => makeClient()->id, 'lines' => [['description' => 'Audit', 'quantity' => '1', 'unit_price' => '3000']]], $fin);
    svc()->submit($q, $fin);
    svc()->approve($q->fresh(), ownerUser());
    $q = svc()->issue($q->fresh(), $fin);
    $rev = svc()->revise($q, $fin);
    expect($rev->document_no)->toBe($q->document_no)->and($rev->revision_no)->toBe(1)->and($rev->status)->toBe(DocumentStatus::Draft);
    svc()->updateDraft($rev, ['lines' => [['description' => 'Audit (reduced scope)', 'quantity' => '1', 'unit_price' => '2500']]], $fin);
    svc()->submit($rev->fresh(), $fin);
    svc()->approve($rev->fresh(), ownerUser());
    svc()->issue($rev->fresh(), $fin);
    expect($q->fresh()->status)->toBe(DocumentStatus::Superseded)->and($rev->fresh()->status)->toBe(DocumentStatus::Issued)
        ->and(DB::table('numbering_sequences')->where('document_type', 'QUOTATION')->where('fiscal_year', (int) today()->format('Y'))->value('next_number'))->toBe(2);
})->group('M03-07', 'M03-01');

it('payment vouchers are rendered by the template engine and registered under the PV number', function (): void {
    $staff = userWithRoles('STF');
    $claims = app(ClaimService::class);
    $claim = $claims->create($staff, ['purpose' => 'Trip', 'lines' => [['claim_type_id' => (int) ClaimType::query()->where('code', 'mileage')->value('id'),
        'incurred_on' => today()->toDateString(), 'description' => 'x', 'distance_km' => '10']]], $staff);
    $claims->submit($claim, $staff);
    $claims->approve($claim->fresh(), userWithRoles('FIN'));
    $pay = app(PaymentService::class);
    $fin = userWithRoles('FIN');
    $p = $pay->create(['payment_date' => today()->toDateString(), 'bank_account_id' => bank()->id, 'method' => 'transfer', 'payee_type' => 'staff', 'payee_id' => $staff->id,
        'payee_name' => $staff->name, 'allocations' => [['target_type' => 'claim', 'target_id' => $claim->id, 'amount' => '6.00']]], $fin);
    $pay->submit($p, $fin);
    $pay->approve($p->fresh(), ownerUser());
    $pay->post($p->fresh(), $fin);
    $p->refresh();
    $register = VoucherDocument::query()->where('source_id', $p->id)->firstOrFail();
    expect($p->voucher_document_id)->not->toBeNull()->and($register->document_no)->toBe($p->payment_no)
        ->and($register->pdf_document_id)->toBe($p->voucher_document_id)->and($register->lines_snapshot['extra']['approved_by'])->not->toBeNull();
})->group('M03-06', 'M01-05');
