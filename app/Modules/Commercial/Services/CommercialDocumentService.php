<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Enums\SettlementStatus;
use App\Modules\Commercial\Events\DocumentIssued;
use App\Modules\Commercial\Events\DocumentVoided;
use App\Modules\Commercial\Events\ProjectBillingChanged;
use App\Modules\Commercial\Events\QuotationConverted;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\CreditNote;
use App\Modules\Commercial\Models\CreditNoteAllocation;
use App\Modules\Commercial\Models\DocumentLine;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Finance\Services\LedgerGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Quotation / invoice / credit note lifecycle:
 *   draft → pending approval (matrix, maker-checker) → approved → ISSUED (gap-free number + issuer/client/lines
 *   snapshot + journal, one transaction) → PDF stored & frozen after commit.
 * Quotations: accepted / rejected by the client, revisions supersede, accepted → project and/or invoice.
 * Invoices: settled by receipts and credit notes; void only while nothing is settled (number kept, VOID copy).
 *
 * @see SRS M03-01, M03-02, M03-03, M03-07, M03-08, WF-04, UAT-04, UAT-06, UAT-11
 */
final class CommercialDocumentService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ApprovalService $approvals,
        private readonly ClientDirectory $clients,
        private readonly ProjectDirectory $projects,
        private readonly LedgerGateway $ledger,
        private readonly IssuerProfile $issuer,
        private readonly TemplateRenderer $templates,
        private readonly DocumentPdfService $pdfs,
    ) {}

    /**
     * @param  array{client_id?: int|null, project_id?: int|null, title?: string|null, terms?: string|null, internal_notes?: string|null, valid_until?: string|null, due_date?: string|null, lines: list<array<string, mixed>>, source_type?: string, source_id?: int|null, related_document_id?: int|null}  $data
     */
    public function createDraft(DocumentType $type, array $data, User $actor): CommercialDocument
    {
        if (! $type->isBuilt()) {
            throw new CommercialRejected("{$type->label()} documents are generated from their postings, not drafted.");
        }

        return DB::transaction(function () use ($type, $data, $actor): CommercialDocument {
            $class = DocumentRegistry::modelFor($type);
            $client = $this->clientSnapshot($data['client_id'] ?? null);
            $settings = $this->templates->currentVersion($type)->settings;
            /** @var CommercialDocument $doc */
            $doc = new $class;
            $doc->forceFill([
                'company_id' => $actor->company_id, 'status' => DocumentStatus::Draft, 'counterparty_type' => 'client',
                'counterparty_id' => $client['id'], 'client_id' => $client['id'], 'counterparty_snapshot' => $client,
                'project_id' => $data['project_id'] ?? null, 'title' => $data['title'] ?? null,
                'terms' => ($data['terms'] ?? null) ?: ($settings['default_terms'] ?? null), 'internal_notes' => $data['internal_notes'] ?? null,
                'valid_until' => $type === DocumentType::Quotation ? ($data['valid_until'] ?? today()->addDays(30)->toDateString()) : null,
                'due_date' => $type === DocumentType::Invoice ? ($data['due_date'] ?? null) : null,
                'source_type' => $data['source_type'] ?? 'manual', 'source_id' => $data['source_id'] ?? null,
                'related_document_id' => $data['related_document_id'] ?? null, 'created_by' => $actor->id,
            ])->save();
            $this->syncLines($doc, $data['lines']);
            Audit::log('create', $doc, null, null, ['type' => $type->value, 'total' => (string) $doc->total->getAmount()], null, $actor);

            return $doc->refresh();
        });
    }

    /** @param  array{client_id?: int|null, project_id?: int|null, title?: string|null, terms?: string|null, internal_notes?: string|null, valid_until?: string|null, due_date?: string|null, lines: list<array<string, mixed>>}  $data */
    public function updateDraft(CommercialDocument $doc, array $data, User $actor): CommercialDocument
    {
        return DB::transaction(function () use ($doc, $data, $actor): CommercialDocument {
            $doc = $this->lock($doc);
            if (! $doc->isEditable()) {
                throw new CommercialRejected('Only drafts can be edited. Return the document to draft first.');
            }
            $before = ['total' => (string) $doc->total->getAmount()];
            $client = $doc->type === DocumentType::CreditNote ? $doc->counterparty_snapshot : $this->clientSnapshot($data['client_id'] ?? $doc->client_id);
            $doc->forceFill([
                'client_id' => $client['id'] ?? $doc->client_id, 'counterparty_id' => $client['id'] ?? $doc->counterparty_id, 'counterparty_snapshot' => $client,
                'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : $doc->project_id,
                'title' => $data['title'] ?? $doc->title, 'terms' => $data['terms'] ?? $doc->terms, 'internal_notes' => $data['internal_notes'] ?? $doc->internal_notes,
                'valid_until' => $doc->type === DocumentType::Quotation ? ($data['valid_until'] ?? $doc->valid_until?->toDateString()) : null,
                'due_date' => $doc->type === DocumentType::Invoice ? ($data['due_date'] ?? $doc->due_date?->toDateString()) : null,
            ])->save();
            $this->syncLines($doc, $data['lines']);
            Audit::log('update', $doc, null, $before, ['total' => (string) $doc->total->getAmount()], null, $actor);

            return $doc->refresh();
        });
    }

    public function discardDraft(CommercialDocument $doc, User $actor): void
    {
        DB::transaction(function () use ($doc, $actor): void {
            $doc = $this->lock($doc);
            if ($doc->status !== DocumentStatus::Draft) {
                throw new CommercialRejected('Only drafts can be discarded.');
            }
            Audit::log('discard', $doc, null, ['status' => 'draft'], null, null, $actor);
            $doc->delete();
        });
    }

    public function submit(CommercialDocument $doc, User $actor): CommercialDocument
    {
        return DB::transaction(function () use ($doc, $actor): CommercialDocument {
            $doc = $this->lock($doc);
            DocumentStatus::transitions()->assert($doc->type->label(), $doc->status, DocumentStatus::PendingApproval);
            if ($doc->lines()->count() === 0 || ! $doc->total->isPositive()) {
                throw new CommercialRejected('Add at least one line with a value before submitting.');
            }
            if ($doc->type !== DocumentType::Quotation && $doc->client_id === null) {
                throw new CommercialRejected('Choose the client this document is billed to.');
            }
            if ($doc instanceof CreditNote) {
                $this->assertCreditable($doc);
            }
            if ($this->approvals->levels($doc->type->value, $doc->total) === []) {
                throw new CommercialRejected("No approval matrix is configured for {$doc->type->label()} documents.");
            }
            $doc->forceFill(['status' => DocumentStatus::PendingApproval, 'current_level' => 1])->save();
            Audit::log('transition', $doc, null, ['status' => 'draft'], ['status' => 'pending_approval'], null, $actor);

            return $doc;
        });
    }

    /** Maker-checker: the preparer cannot approve unless an Owner records a reason (UAT-11). */
    public function approve(CommercialDocument $doc, User $approver, ?string $overrideReason = null): CommercialDocument
    {
        return DB::transaction(function () use ($doc, $approver, $overrideReason): CommercialDocument {
            $doc = $this->lock($doc);
            if ($doc->status !== DocumentStatus::PendingApproval) {
                throw new CommercialRejected('Only documents pending approval can be approved.');
            }
            $type = $doc->type->value;
            $decision = $this->approvals->authorize($approver, $type, $doc->total, $doc->current_level, $doc->created_by, $overrideReason, $this->approvals->priorApprovers($type, $doc->id));
            $this->approvals->record($type, $doc->id, $decision, $approver);
            if ($decision->ownerOverride) {
                $this->approvals->auditOverride($doc, $approver, (string) $overrideReason);
            }
            Audit::log('approve', $doc, null, ['level' => $doc->current_level], ['level' => $decision->level, 'final' => $decision->finalLevel], $overrideReason, $approver);
            if (! $decision->finalLevel) {
                $doc->forceFill(['current_level' => $doc->current_level + 1])->save();

                return $doc;
            }
            DocumentStatus::transitions()->assert($doc->type->label(), $doc->status, DocumentStatus::Approved);
            $doc->forceFill(['status' => DocumentStatus::Approved, 'approved_by' => $approver->id, 'approved_at' => now()])->save();

            return $doc;
        });
    }

    /** Approver sends it back (or the preparer recalls an approved, not-yet-issued document). */
    public function returnToDraft(CommercialDocument $doc, User $actor, string $reason): CommercialDocument
    {
        return DB::transaction(function () use ($doc, $actor, $reason): CommercialDocument {
            $doc = $this->lock($doc);
            DocumentStatus::transitions()->assert($doc->type->label(), $doc->status, DocumentStatus::Draft);
            $from = $doc->status->value;
            $doc->forceFill(['status' => DocumentStatus::Draft, 'current_level' => 0, 'approved_by' => null, 'approved_at' => null])->save();
            $this->approvals->reset($doc->type->value, $doc->id);
            Audit::log('return', $doc, null, ['status' => $from], ['status' => 'draft'], $reason, $actor);

            return $doc;
        });
    }

    /**
     * Allocate the gap-free number, snapshot issuer / client / lines / template, post the journal (invoice, credit
     * note) — all in ONE short transaction; the PDF is rendered after commit (CLAUDE.md rule 6).
     */
    public function issue(CommercialDocument $doc, User $actor, ?string $issueDate = null): CommercialDocument
    {
        $issued = DB::transaction(function () use ($doc, $actor, $issueDate): CommercialDocument {
            $doc = $this->lock($doc);
            DocumentStatus::transitions()->assert($doc->type->label(), $doc->status, DocumentStatus::Issued);
            $date = $issueDate !== null ? Carbon::parse($issueDate) : today();
            $doc->load('lines');
            $client = $doc->type === DocumentType::CreditNote ? $doc->counterparty_snapshot : $this->clientSnapshot($doc->client_id);
            $version = $this->templates->currentVersion($doc->type);
            $number = $doc->document_no ?? $this->numbering->allocate((int) $doc->company_id, $doc->type->sequence(), (int) $date->format('Y'))['formatted'];
            $extra = $this->references($doc);
            $fields = [
                'document_no' => $number, 'issue_date' => $date->toDateString(), 'template_version_id' => $version->id,
                'issuer_profile_snapshot' => $this->issuer->snapshot(), 'counterparty_snapshot' => $client,
                'lines_snapshot' => ['lines' => $doc->lines->map(fn (DocumentLine $l): array => $this->lineSnapshot($l))->values()->all(), 'extra' => $extra],
                'status' => DocumentStatus::Issued, 'issued_by' => $actor->id, 'issued_at' => now(),
            ];
            if ($doc instanceof Invoice) {
                $days = (int) ($client['payment_terms_days'] ?? 30);
                $fields['due_date'] = $doc->due_date?->toDateString() ?? $date->copy()->addDays($days)->toDateString();
                $fields['settlement_status'] = SettlementStatus::Unpaid;
                $fields['lines_snapshot']['extra']['terms_days'] = (string) $days;
            }
            // Post first, then write the issued row once (issued rows are immutable apart from settlement fields).
            if ($doc instanceof Invoice) {
                $fields['journal_id'] = $this->ledger->postInvoice($doc->id, $number, (string) ($client['name'] ?? ''), $date, $this->postingLines($doc), $actor, $doc->project_id);
            }
            $invoice = null;
            if ($doc instanceof CreditNote) {
                $invoice = $this->assertCreditable($doc);
                $fields['journal_id'] = $this->ledger->postCreditNote($doc->id, $number, (string) $invoice->document_no, $date, $this->postingLines($doc), $actor, $invoice->project_id);
            }
            $doc->forceFill($fields)->save();
            if ($invoice !== null) {
                CreditNoteAllocation::query()->create(['credit_note_id' => $doc->id, 'invoice_id' => $invoice->id, 'amount' => $doc->total]);
                $this->settle($invoice->id);
            }
            if ($doc->supersedes_id !== null) {
                $previous = DocumentRegistry::find($doc->supersedes_id);
                if ($previous !== null && in_array($previous->status, [DocumentStatus::Issued, DocumentStatus::Rejected, DocumentStatus::Accepted], true)) {
                    $previous->forceFill(['status' => DocumentStatus::Superseded])->save();
                    Audit::log('supersede', $previous, null, null, ['superseded_by' => $doc->id], null, $actor);
                }
            }
            Audit::log('issue', $doc, null, ['status' => 'approved'], ['status' => 'issued', 'document_no' => $number, 'total' => (string) $doc->total->getAmount()], null, $actor);

            return $doc;
        });

        $this->pdfs->storeIssued($issued, $actor);
        Event::dispatch(new DocumentIssued($issued->id, $issued->type->value));
        $this->projectBillingChanged($issued->project_id ?? ($issued instanceof CreditNote ? Invoice::query()->whereKey($issued->related_document_id)->value('project_id') : null));

        return $issued->refresh();
    }

    public function regeneratePdf(CommercialDocument $doc, User $actor): ?int
    {
        if (! $doc->status->isIssued()) {
            throw new CommercialRejected('Only issued documents have a PDF.');
        }

        return $this->pdfs->storeIssued($doc, $actor);
    }

    /** Client accepted / rejected an issued quotation (WF-04). */
    public function recordClientResponse(Quotation $quotation, bool $accepted, User $actor, ?string $note = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $accepted, $actor, $note): Quotation {
            /** @var Quotation $q */
            $q = $this->lock($quotation);
            $to = $accepted ? DocumentStatus::Accepted : DocumentStatus::Rejected;
            DocumentStatus::transitions()->assert('Quotation', $q->status, $to);
            if ($accepted && $q->valid_until !== null && $q->valid_until->lt(today())) {
                throw new CommercialRejected("Quotation {$q->document_no} expired on {$q->valid_until->toDateString()}; issue a revision first.");
            }
            $q->forceFill(['status' => $to, 'client_response_at' => now()])->save();
            Audit::log($accepted ? 'accept' : 'reject', $q, null, ['status' => 'issued'], ['status' => $to->value], $note, $actor);

            return $q;
        });
    }

    /** A new draft revision (same number, revision_no + 1); issuing it supersedes the current one. */
    public function revise(Quotation $quotation, User $actor): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor): Quotation {
            /** @var Quotation $q */
            $q = $this->lock($quotation);
            if (! in_array($q->status, [DocumentStatus::Issued, DocumentStatus::Rejected], true)) {
                throw new CommercialRejected('Only an issued or client-rejected quotation can be revised.');
            }
            if (Quotation::query()->where('supersedes_id', $q->id)->exists()) {
                throw new CommercialRejected('A revision of this quotation already exists.');
            }
            $next = (int) Quotation::query()->where('document_no', $q->document_no)->max('revision_no') + 1;
            $rev = new Quotation;
            $rev->forceFill([
                'company_id' => $q->company_id, 'document_no' => $q->document_no, 'revision_no' => $next, 'status' => DocumentStatus::Draft,
                'counterparty_type' => 'client', 'counterparty_id' => $q->client_id, 'client_id' => $q->client_id, 'counterparty_snapshot' => $q->counterparty_snapshot,
                'project_id' => $q->project_id, 'title' => $q->title, 'terms' => $q->terms, 'internal_notes' => $q->internal_notes,
                'valid_until' => today()->addDays(30)->toDateString(), 'source_type' => 'revision', 'source_id' => $q->id, 'supersedes_id' => $q->id, 'created_by' => $actor->id,
            ])->save();
            $this->syncLines($rev, $q->lines()->get()->map(fn (DocumentLine $l): array => $this->lineInput($l, false))->all());
            Audit::log('revise', $q, null, null, ['revision_id' => $rev->id, 'revision_no' => $next], null, $actor);

            return $rev->refresh();
        });
    }

    /** Void keeps the number and stores a VOID-watermarked copy; postings are reversed (M03-07, §6.3). */
    public function void(CommercialDocument $doc, User $actor, string $reason): CommercialDocument
    {
        if (trim($reason) === '') {
            throw new CommercialRejected('A reason is required to void a document.');
        }
        $voided = DB::transaction(function () use ($doc, $actor, $reason): CommercialDocument {
            $doc = $this->lock($doc);
            DocumentStatus::transitions()->assert($doc->type->label(), $doc->status, DocumentStatus::Void);
            $fields = ['status' => DocumentStatus::Void, 'void_reason' => $reason, 'voided_at' => now()];
            if ($doc instanceof Invoice) {
                if (! $doc->amount_received->isZero() || ! $doc->amount_credited->isZero()) {
                    throw new CommercialRejected("Invoice {$doc->document_no} has receipts or credit notes; void those or issue a credit note instead.");
                }
                $fields['reversal_journal_id'] = $this->ledger->reverse((int) $doc->journal_id, $actor, "Void {$doc->document_no}: {$reason}");
            }
            if ($doc instanceof CreditNote) {
                $fields['reversal_journal_id'] = $this->ledger->reverse((int) $doc->journal_id, $actor, "Void {$doc->document_no}: {$reason}");
            }
            $doc->forceFill($fields)->save();
            if ($doc instanceof CreditNote) {
                CreditNoteAllocation::query()->where('credit_note_id', $doc->id)->whereNull('voided_at')->update(['voided_at' => now()]);
                $this->settle((int) $doc->related_document_id);
            }
            Audit::log('void', $doc, null, null, ['status' => 'void'], $reason, $actor);

            return $doc;
        });
        $this->pdfs->storeVoidCopy($voided, $actor);
        Event::dispatch(new DocumentVoided($voided->id, $voided->type->value));
        $this->projectBillingChanged($voided->project_id ?? ($voided instanceof CreditNote ? Invoice::query()->whereKey($voided->related_document_id)->value('project_id') : null));

        return $voided->refresh();
    }

    /** Accepted quotation → project stub; client and scope carried over, source link kept (UAT-06). */
    public function convertToProject(Quotation $q, User $actor, ?string $name = null): int
    {
        if ($q->status !== DocumentStatus::Accepted) {
            throw new CommercialRejected('Only an accepted quotation can be converted to a project.');
        }
        $existing = $this->projects->idForQuotation($q->id);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->projects->createFromQuotation([
            'client_id' => (int) $q->client_id, 'name' => $name ?: ($q->title ?: "Project from {$q->document_no}"),
            'scope' => $q->lines()->pluck('description')->implode("\n"), 'contract_value' => (string) $q->subtotal->getAmount(), 'source_quotation_id' => $q->id,
        ], $actor);
        Audit::log('convert', $q, null, null, ['project_id' => $id], null, $actor);
        Event::dispatch(new QuotationConverted($q->id, 'project', $id));

        return $id;
    }

    /** Accepted quotation → invoice draft with the lines carried over (no re-keying, M03-02). */
    public function convertToInvoice(Quotation $q, User $actor): Invoice
    {
        if ($q->status !== DocumentStatus::Accepted) {
            throw new CommercialRejected('Only an accepted quotation can be invoiced.');
        }
        $invoice = $this->createDraft(DocumentType::Invoice, [
            'client_id' => $q->client_id, 'project_id' => $this->projects->idForQuotation($q->id), 'title' => $q->title,
            'lines' => $q->lines()->get()->map(fn (DocumentLine $l): array => $this->lineInput($l, true))->all(),
            'source_type' => 'quotation', 'source_id' => $q->id,
        ], $actor);
        Audit::log('convert', $q, null, null, ['invoice_id' => $invoice->id], null, $actor);
        Event::dispatch(new QuotationConverted($q->id, 'invoice', $invoice->id));

        /** @var Invoice $invoice */
        return $invoice;
    }

    /**
     * Credit note draft against an issued invoice, defaulting to its lines (amounts editable, ≤ outstanding).
     *
     * @param  list<array<string, mixed>>|null  $lines
     */
    public function draftCreditNote(Invoice $invoice, User $actor, ?array $lines = null): CreditNote
    {
        if (! in_array($invoice->status, [DocumentStatus::Issued, DocumentStatus::Accepted], true)) {
            throw new CommercialRejected('Credit notes can only be raised against an issued invoice.');
        }
        if (! $invoice->outstanding()->isPositive()) {
            throw new CommercialRejected("Invoice {$invoice->document_no} has nothing left to credit.");
        }
        /** @var CreditNote $cn */
        $cn = DB::transaction(function () use ($invoice, $actor, $lines): CreditNote {
            $cn = new CreditNote;
            $cn->forceFill([
                'company_id' => $invoice->company_id, 'status' => DocumentStatus::Draft, 'counterparty_type' => 'client', 'counterparty_id' => $invoice->client_id,
                'client_id' => $invoice->client_id, 'counterparty_snapshot' => $invoice->counterparty_snapshot, 'project_id' => $invoice->project_id,
                'related_document_id' => $invoice->id, 'title' => "Credit against {$invoice->document_no}", 'source_type' => 'invoice', 'source_id' => $invoice->id,
                'terms' => $this->templates->currentVersion(DocumentType::CreditNote)->settings['default_terms'] ?? null, 'created_by' => $actor->id,
            ])->save();
            $this->syncLines($cn, $lines ?? $invoice->lines()->get()->map(fn (DocumentLine $l): array => $this->lineInput($l, true))->all());
            Audit::log('create', $cn, null, null, ['invoice_id' => $invoice->id], null, $actor);

            return $cn->refresh();
        });

        return $cn;
    }

    /** Recompute an invoice's settled amounts from the allocation tables (source of truth). */
    public function settle(int $invoiceId): Invoice
    {
        /** @var Invoice $inv */
        $inv = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);
        $received = Money::fromDb((string) DB::table('receipt_allocations')->where('invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount'));
        $credited = Money::fromDb((string) DB::table('credit_note_allocations')->where('invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount'));
        $settled = $received->plus($credited);
        $status = $settled->isZero() ? SettlementStatus::Unpaid : ($settled->isGreaterThanOrEqualTo($inv->total) ? SettlementStatus::Paid : SettlementStatus::PartiallyPaid);
        $inv->forceFill(['amount_received' => $received, 'amount_credited' => $credited, 'settlement_status' => $status])->save();

        return $inv;
    }

    public function projectBillingChanged(?int $projectId): void
    {
        if ($projectId === null) {
            return;
        }
        $row = DB::table('commercial_documents')->where('type', 'invoice')->where('project_id', $projectId)->whereIn('status', ['issued', 'accepted'])
            ->selectRaw('COALESCE(SUM(total),0) AS invoiced, COALESCE(SUM(amount_received + amount_credited),0) AS settled')->first();
        Event::dispatch(new ProjectBillingChanged($projectId, (string) Money::fromDb((string) ($row->invoiced ?? '0'))->getAmount(), (string) Money::fromDb((string) ($row->settled ?? '0'))->getAmount()));
    }

    private function assertCreditable(CreditNote $cn): Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->lockForUpdate()->find($cn->related_document_id);
        if ($invoice === null || ! in_array($invoice->status, [DocumentStatus::Issued, DocumentStatus::Accepted], true)) {
            throw new CommercialRejected('The credited invoice is not issued.');
        }
        if ($cn->total->isGreaterThan($invoice->outstanding())) {
            throw new CommercialRejected("Credit note total exceeds the outstanding balance of {$invoice->document_no} (RM {$invoice->outstanding()->getAmount()}).");
        }

        return $invoice;
    }

    private function lock(CommercialDocument $doc): CommercialDocument
    {
        return $doc::query()->lockForUpdate()->findOrFail($doc->id);
    }

    /** @return array<string, mixed> */
    private function clientSnapshot(?int $clientId): array
    {
        if ($clientId === null) {
            throw new CommercialRejected('Choose a client.');
        }

        return $this->clients->snapshot($clientId) ?? throw new CommercialRejected('Unknown client.');
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(CommercialDocument $doc, array $lines): void
    {
        if ($lines === []) {
            throw new CommercialRejected('Add at least one line.');
        }
        if (count($lines) > 200) {
            throw new CommercialRejected('A document can have at most 200 lines.');
        }
        $doc->lines()->delete();
        $subtotal = Money::zero();
        $discount = Money::zero();
        $tax = Money::zero();
        foreach (array_values($lines) as $i => $l) {
            $rate = '0';
            $taxCodeId = isset($l['tax_code_id']) && $l['tax_code_id'] !== '' ? (int) $l['tax_code_id'] : null;
            if ($taxCodeId !== null) {
                $code = DictionaryItem::query()->whereKey($taxCodeId)->whereRelation('dictionary', 'code', 'tax_code')->first()
                    ?? throw new CommercialRejected('Unknown tax code on line '.($i + 1).'.');
                if (! $code->active) {
                    throw new CommercialRejected("Tax code {$code->code} is not active (SST registration not configured).");
                }
                $rate = (string) ($code->meta['rate'] ?? '0');
            }
            $math = DocumentMath::line((string) $l['quantity'], (string) $l['unit_price'], (string) ($l['discount'] ?? '0'), $rate);
            DocumentLine::query()->create([
                'commercial_document_id' => $doc->id, 'line_no' => $i + 1, 'description' => trim((string) $l['description']),
                'quantity' => (string) $l['quantity'], 'unit' => $l['unit'] ?? null, 'unit_price' => Money::myr((string) $l['unit_price']),
                'discount' => Money::myr((string) (($l['discount'] ?? '') === '' ? '0' : $l['discount'])), 'tax_code_id' => $taxCodeId, 'tax_rate' => $rate,
                'net_amount' => $math['net'], 'tax_amount' => $math['tax'], 'line_total' => $math['total'],
                'revenue_account_id' => isset($l['revenue_account_id']) && $l['revenue_account_id'] !== '' ? (int) $l['revenue_account_id'] : null,
                'source_line_id' => $l['source_line_id'] ?? null,
            ]);
            $subtotal = $subtotal->plus($math['net']);
            $discount = $discount->plus($math['gross']->minus($math['net']));
            $tax = $tax->plus($math['tax']);
        }
        $doc->forceFill(['subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax, 'total' => $subtotal->plus($tax)])->save();
    }

    /** @return array<string, mixed> */
    private function lineInput(DocumentLine $l, bool $keepSource): array
    {
        return [
            'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit, 'unit_price' => (string) $l->unit_price->getAmount(),
            'discount' => (string) $l->discount->getAmount(), 'tax_code_id' => $l->tax_code_id, 'revenue_account_id' => $l->revenue_account_id,
            'source_line_id' => $keepSource ? $l->id : $l->source_line_id,
        ];
    }

    /** @return array<string, mixed> */
    private function lineSnapshot(DocumentLine $l): array
    {
        return [
            'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit' => $l->unit,
            'unit_price' => (string) $l->unit_price->getAmount(), 'discount' => (string) $l->discount->getAmount(), 'tax_rate' => (string) $l->tax_rate,
            'net' => (string) $l->net_amount->getAmount(), 'tax' => (string) $l->tax_amount->getAmount(), 'total' => (string) $l->line_total->getAmount(),
        ];
    }

    /** @return list<array{revenue_account_id: int|null, net: string, tax: string, description: string}> */
    private function postingLines(CommercialDocument $doc): array
    {
        return $doc->lines->map(fn (DocumentLine $l): array => [
            'revenue_account_id' => $l->revenue_account_id, 'net' => (string) $l->net_amount->getAmount(), 'tax' => (string) $l->tax_amount->getAmount(), 'description' => $l->description,
        ])->values()->all();
    }

    /** @return array<string, string> */
    private function references(CommercialDocument $doc): array
    {
        $refs = [];
        if ($doc->source_type === 'quotation' && $doc->source_id !== null) {
            $refs['quotation_no'] = (string) Quotation::query()->whereKey($doc->source_id)->value('document_no');
        }
        if ($doc instanceof CreditNote) {
            $refs['invoice_no'] = (string) Invoice::query()->whereKey($doc->related_document_id)->value('document_no');
        }
        if ($doc->project_id !== null) {
            $p = $this->projects->snapshot($doc->project_id);
            if ($p !== null) {
                $refs['project'] = "{$p['code']} {$p['name']}";
            }
        }

        return $refs;
    }
}
