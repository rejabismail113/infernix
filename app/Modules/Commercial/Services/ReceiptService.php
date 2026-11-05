<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Events\ReceiptPosted;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Receipt;
use App\Modules\Commercial\Models\ReceiptAllocation;
use App\Modules\Commercial\Models\ReceiptDocument;
use App\Modules\Finance\Services\LedgerGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Official receipts are created ONLY together with the cash posting (Dr Bank / Cr AR [/ Cr Unapplied]) in a
 * single transaction — there is no "draft receipt" that could be printed (M03-04). The RC number is allocated
 * in the same transaction; the PDF is rendered after commit from the register snapshot.
 *
 * @see SRS M03-04, M03-03, UAT-04, WF-04
 */
final class ReceiptService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly LedgerGateway $ledger,
        private readonly ClientDirectory $clients,
        private readonly CommercialDocumentService $documents,
        private readonly IssuerProfile $issuer,
        private readonly TemplateRenderer $templates,
        private readonly DocumentPdfService $pdfs,
    ) {}

    /**
     * @param  array{receipt_date: string, bank_account_id: int, amount: string, method: string, reference?: string|null, client_id?: int|null, payer_name?: string|null, notes?: string|null, allocations?: list<array{invoice_id: int, amount: string}>}  $data
     */
    public function record(array $data, User $actor, ?string $idempotencyKey = null): Receipt
    {
        $amount = Money::myr($data['amount']);
        if (! $amount->isPositive()) {
            throw new CommercialRejected('The amount received must be greater than zero.');
        }
        $allocations = array_values(array_filter($data['allocations'] ?? [], fn (array $a): bool => Money::myr($a['amount'])->isPositive()));
        $allocated = Money::zero();
        foreach ($allocations as $a) {
            $allocated = $allocated->plus(Money::myr($a['amount']));
        }
        if ($allocated->isGreaterThan($amount)) {
            throw new CommercialRejected('Allocations exceed the amount received.');
        }
        if (count(array_unique(array_column($allocations, 'invoice_id'))) !== count($allocations)) {
            throw new CommercialRejected('Each invoice can appear only once on a receipt.');
        }

        $receipt = DB::transaction(function () use ($data, $actor, $idempotencyKey, $amount, $allocated, $allocations): Receipt {
            $date = Carbon::parse($data['receipt_date']);
            $client = isset($data['client_id']) ? $this->clients->snapshot((int) $data['client_id']) : null;
            $payer = trim((string) ($data['payer_name'] ?? '')) ?: (string) ($client['name'] ?? '');
            if ($payer === '') {
                throw new CommercialRejected('Name the payer or choose a client.');
            }
            $invoices = [];
            foreach ($allocations as $a) {
                /** @var Invoice|null $inv */
                $inv = Invoice::query()->lockForUpdate()->find($a['invoice_id']);
                if ($inv === null || ! in_array($inv->status, [DocumentStatus::Issued, DocumentStatus::Accepted], true)) {
                    throw new CommercialRejected('Receipts can only be allocated to issued invoices.');
                }
                if ($client !== null && $inv->client_id !== $client['id']) {
                    throw new CommercialRejected("Invoice {$inv->document_no} belongs to another client.");
                }
                if (Money::myr($a['amount'])->isGreaterThan($inv->outstanding())) {
                    throw new CommercialRejected("RM {$a['amount']} exceeds the outstanding balance of {$inv->document_no} (RM {$inv->outstanding()->getAmount()}).");
                }
                $invoices[] = $inv;
            }
            $no = $this->numbering->allocate((int) $actor->company_id, 'RECEIPT', (int) $date->format('Y'))['formatted'];
            // The journal needs the receipt id for traceability, so insert the receipt with its journal in one
            // statement chain: reserve the id first, post, then insert (the row never exists without a journal).
            $id = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('receipts', 'id')) AS id")->id;
            $journalId = $this->ledger->postReceipt($id, $no, $payer, $date, (int) $data['bank_account_id'], (string) $amount->getAmount(), (string) $allocated->getAmount(), $actor);
            $receipt = new Receipt;
            $receipt->forceFill([
                'id' => $id, 'company_id' => $actor->company_id, 'receipt_no' => $no, 'receipt_date' => $date->toDateString(),
                'bank_account_id' => $data['bank_account_id'], 'amount' => $amount, 'unapplied_amount' => $amount->minus($allocated),
                'method' => $data['method'], 'reference' => $data['reference'] ?? null, 'client_id' => $client['id'] ?? null, 'payer_name' => $payer,
                'notes' => $data['notes'] ?? null, 'journal_id' => $journalId, 'status' => 'posted', 'idempotency_key' => $idempotencyKey, 'created_by' => $actor->id,
            ])->save();
            foreach ($allocations as $a) {
                ReceiptAllocation::query()->create(['receipt_id' => $receipt->id, 'invoice_id' => $a['invoice_id'], 'amount' => Money::myr($a['amount'])]);
            }
            foreach ($invoices as $inv) {
                $this->documents->settle($inv->id);
            }
            $this->register($receipt, $client, $invoices, $actor);
            Audit::log('post', $receipt, null, null, ['receipt_no' => $no, 'amount' => (string) $amount->getAmount(), 'allocated' => (string) $allocated->getAmount()], null, $actor);

            return $receipt;
        });

        $this->storePdf($receipt, $actor);
        Event::dispatch(new ReceiptPosted($receipt->id));
        foreach (array_unique(array_filter(Invoice::query()->whereIn('id', array_column($allocations, 'invoice_id'))->pluck('project_id')->all())) as $projectId) {
            $this->documents->projectBillingChanged((int) $projectId);
        }

        return $receipt->refresh();
    }

    /** The PDF exists only for a POSTED receipt (journal present, not void) — M03-04. */
    public function storePdf(Receipt $receipt, User $actor): ?int
    {
        $receipt->refresh();
        if ($receipt->status !== 'posted' || $receipt->journal_id === null) {
            throw new CommercialRejected('A receipt PDF can only be generated for a posted receipt.');
        }
        $doc = ReceiptDocument::query()->where('source_type', 'receipt')->where('source_id', $receipt->id)->firstOrFail();
        $id = $this->pdfs->storeIssued($doc, $actor);
        if ($id !== null && $receipt->pdf_document_id === null) {
            $receipt->forceFill(['pdf_document_id' => $id])->save();
        }

        return $id;
    }

    /** Void: reverse the posting, release the allocations; the RC number is kept and marked VOID. */
    public function void(Receipt $receipt, User $actor, string $reason): Receipt
    {
        if (trim($reason) === '') {
            throw new CommercialRejected('A reason is required to void a receipt.');
        }
        $invoiceIds = [];
        $voided = DB::transaction(function () use ($receipt, $actor, $reason, &$invoiceIds): Receipt {
            /** @var Receipt $r */
            $r = Receipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($r->status !== 'posted') {
                throw new CommercialRejected("Receipt {$r->receipt_no} is already void.");
            }
            $reversal = $this->ledger->reverse($r->journal_id, $actor, "Void {$r->receipt_no}: {$reason}");
            $r->forceFill(['status' => 'void', 'reversal_journal_id' => $reversal, 'void_reason' => $reason, 'voided_at' => now()])->save();
            $invoiceIds = ReceiptAllocation::query()->where('receipt_id', $r->id)->whereNull('voided_at')->pluck('invoice_id')->all();
            ReceiptAllocation::query()->where('receipt_id', $r->id)->whereNull('voided_at')->update(['voided_at' => now()]);
            foreach ($invoiceIds as $invoiceId) {
                $this->documents->settle((int) $invoiceId);
            }
            $doc = ReceiptDocument::query()->where('source_type', 'receipt')->where('source_id', $r->id)->first();
            $doc?->forceFill(['status' => DocumentStatus::Void, 'void_reason' => $reason, 'voided_at' => now()])->save();
            Audit::log('void', $r, null, ['status' => 'posted'], ['status' => 'void'], $reason, $actor);

            return $r;
        });
        $doc = ReceiptDocument::query()->where('source_type', 'receipt')->where('source_id', $voided->id)->first();
        if ($doc !== null) {
            $this->pdfs->storeVoidCopy($doc, $actor);
        }
        foreach (array_unique(array_filter(Invoice::query()->whereIn('id', $invoiceIds)->pluck('project_id')->all())) as $projectId) {
            $this->documents->projectBillingChanged((int) $projectId);
        }

        return $voided->refresh();
    }

    /**
     * Register row in commercial_documents (same RC number) holding the render snapshot.
     *
     * @param  array<string, mixed>|null  $client
     * @param  list<Invoice>  $invoices
     */
    private function register(Receipt $receipt, ?array $client, array $invoices, User $actor): void
    {
        $lines = [];
        foreach ($receipt->allocations()->get() as $a) {
            $inv = collect($invoices)->firstWhere('id', $a->invoice_id);
            $lines[] = ['ref' => (string) $inv?->document_no, 'description' => 'Payment for invoice '.$inv?->document_no, 'amount' => (string) $a->amount->getAmount()];
        }
        if ($receipt->unapplied_amount->isPositive()) {
            $lines[] = ['ref' => '—', 'description' => 'Advance / unapplied payment', 'amount' => (string) $receipt->unapplied_amount->getAmount()];
        }
        $bank = collect($this->ledger->bankAccountOptions())->firstWhere('id', $receipt->bank_account_id);
        $doc = new ReceiptDocument;
        $doc->forceFill([
            'company_id' => $receipt->company_id, 'document_no' => $receipt->receipt_no, 'revision_no' => 0, 'status' => DocumentStatus::Issued,
            'template_version_id' => $this->templates->currentVersion(DocumentType::Receipt)->id, 'issuer_profile_snapshot' => $this->issuer->snapshot(),
            'counterparty_type' => 'client', 'counterparty_id' => $client['id'] ?? null, 'client_id' => $client['id'] ?? null,
            'counterparty_snapshot' => $client ?? ['name' => $receipt->payer_name],
            'lines_snapshot' => ['lines' => $lines, 'extra' => ['method' => strtoupper($receipt->method), 'reference' => $receipt->reference, 'bank' => $bank['label'] ?? null, 'received_by' => $actor->name]],
            'subtotal' => $receipt->amount, 'tax_total' => Money::zero(), 'total' => $receipt->amount, 'issue_date' => $receipt->receipt_date->toDateString(),
            'source_type' => 'receipt', 'source_id' => $receipt->id, 'journal_id' => $receipt->journal_id, 'created_by' => $actor->id,
            'issued_by' => $actor->id, 'issued_at' => now(),
        ])->save();
    }
}
