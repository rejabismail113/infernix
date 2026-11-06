<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Reports;

use App\Kernel\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M03 outputs: quotation register, invoice register, document issuance history (money as decimal strings).
 *
 * @see SRS M03 (Output / reporting), §8.1
 */
final class CommercialReports
{
    /** @return array<string, mixed> */
    public function quotationRegister(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('commercial_documents')->where('type', 'quotation')->whereNotNull('document_no')
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->orderBy('document_no')->orderBy('revision_no')
            ->get(['id', 'document_no', 'revision_no', 'issue_date', 'valid_until', 'status', 'total', 'title', DB::raw("counterparty_snapshot->>'name' as client")])
            ->map(fn ($r): array => ['id' => $r->id, 'document_no' => $r->document_no, 'revision' => (string) $r->revision_no, 'issue_date' => (string) $r->issue_date, 'valid_until' => (string) $r->valid_until,
                'client' => $r->client, 'title' => $r->title, 'status' => $r->status, 'total' => (string) Money::fromDb((string) $r->total)->getAmount()])->all();
        $live = collect($rows)->whereNotIn('status', ['void', 'superseded']);
        $sum = fn ($c) => (string) $c->reduce(fn ($carry, $r) => $carry->plus(Money::myr($r['total'])), Money::zero())->getAmount();

        return [
            'title' => 'Quotation register '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'document_no', 'label' => 'Quotation'], ['key' => 'revision', 'label' => 'Rev'], ['key' => 'issue_date', 'label' => 'Issued'], ['key' => 'valid_until', 'label' => 'Valid until'], ['key' => 'client', 'label' => 'Client'], ['key' => 'title', 'label' => 'Title'], ['key' => 'status', 'label' => 'Status'], ['key' => 'total', 'label' => 'Total', 'numeric' => true]],
            'rows' => $rows,
            'totals' => ['quoted' => $sum($live), 'accepted' => $sum($live->where('status', 'accepted'))],
            'notes' => ['Totals exclude void and superseded revisions.'],
        ];
    }

    /** @return array<string, mixed> */
    public function invoiceRegister(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('commercial_documents')->where('type', 'invoice')->whereNotNull('document_no')
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->orderBy('document_no')
            ->get(['id', 'document_no', 'issue_date', 'due_date', 'status', 'settlement_status', 'subtotal', 'tax_total', 'total', 'amount_received', 'amount_credited', DB::raw("counterparty_snapshot->>'name' as client")])
            ->map(function ($r): array {
                $total = Money::fromDb((string) $r->total);
                $out = $r->status === 'void' ? Money::zero() : $total->minus(Money::fromDb((string) $r->amount_received))->minus(Money::fromDb((string) $r->amount_credited));

                return ['id' => $r->id, 'document_no' => $r->document_no, 'issue_date' => (string) $r->issue_date, 'due_date' => (string) $r->due_date, 'client' => $r->client,
                    'status' => $r->status, 'settlement' => $r->settlement_status, 'subtotal' => (string) Money::fromDb((string) $r->subtotal)->getAmount(), 'tax' => (string) Money::fromDb((string) $r->tax_total)->getAmount(),
                    'total' => (string) $total->getAmount(), 'received' => (string) Money::fromDb((string) $r->amount_received)->getAmount(),
                    'credited' => (string) Money::fromDb((string) $r->amount_credited)->getAmount(), 'outstanding' => (string) $out->getAmount()];
            })->all();
        $live = collect($rows)->where('status', '!=', 'void');
        $sum = fn (string $k) => (string) $live->reduce(fn ($c, $r) => $c->plus(Money::myr($r[$k])), Money::zero())->getAmount();

        return [
            'title' => 'Invoice register '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'document_no', 'label' => 'Invoice'], ['key' => 'issue_date', 'label' => 'Issued'], ['key' => 'due_date', 'label' => 'Due'], ['key' => 'client', 'label' => 'Client'], ['key' => 'status', 'label' => 'Status'], ['key' => 'settlement', 'label' => 'Settlement'],
                ['key' => 'total', 'label' => 'Total', 'numeric' => true], ['key' => 'received', 'label' => 'Received', 'numeric' => true], ['key' => 'credited', 'label' => 'Credited', 'numeric' => true], ['key' => 'outstanding', 'label' => 'Outstanding', 'numeric' => true]],
            'rows' => $rows,
            'totals' => ['invoiced' => $sum('total'), 'received' => $sum('received'), 'credited' => $sum('credited'), 'outstanding' => $sum('outstanding')],
            'notes' => ['Void invoices are listed (numbers are never reused) but excluded from totals.'],
        ];
    }

    /** @return array<string, mixed> */
    public function issuanceHistory(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('commercial_documents as d')->leftJoin('users as u', 'u.id', '=', 'd.issued_by')->whereNotNull('d.document_no')->whereNotNull('d.issued_at')
            ->whereBetween('d.issue_date', [$from->toDateString(), $to->toDateString()])->orderByDesc('d.issued_at')
            ->get(['d.id', 'd.type', 'd.document_no', 'd.revision_no', 'd.issue_date', 'd.status', 'd.total', 'd.pdf_document_id', 'd.void_reason', 'u.name as issued_by', DB::raw("d.counterparty_snapshot->>'name' as counterparty")])
            ->map(fn ($r): array => ['id' => $r->id, 'type' => $r->type, 'document_no' => $r->document_no.($r->revision_no > 0 ? ' rev '.$r->revision_no : ''), 'issue_date' => (string) $r->issue_date,
                'counterparty' => $r->counterparty, 'status' => $r->status, 'total' => (string) Money::fromDb((string) $r->total)->getAmount(), 'issued_by' => $r->issued_by,
                'pdf' => $r->pdf_document_id !== null ? 'stored' : 'missing', 'void_reason' => $r->void_reason])->all();

        return [
            'title' => 'Document issuance history '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'type', 'label' => 'Type'], ['key' => 'document_no', 'label' => 'Number'], ['key' => 'issue_date', 'label' => 'Date'], ['key' => 'counterparty', 'label' => 'Counterparty'], ['key' => 'status', 'label' => 'Status'], ['key' => 'total', 'label' => 'Total', 'numeric' => true], ['key' => 'issued_by', 'label' => 'Issued by'], ['key' => 'pdf', 'label' => 'PDF'], ['key' => 'void_reason', 'label' => 'Void reason']],
            'rows' => $rows, 'totals' => [], 'notes' => ['Every issued number appears exactly once per revision; void documents keep their number.'],
        ];
    }

    /**
     * Cash collected from receipts per month (posted, not void) — M11-02 collections.
     *
     * @return list<array{month: string, collected: string}>
     */
    public function collectionsByMonth(Carbon $from, Carbon $to): array
    {
        $byMonth = DB::table('receipts')->where('status', 'posted')->whereBetween('receipt_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("to_char(receipt_date, 'YYYY-MM') AS m, COALESCE(SUM(amount),0) AS total")->groupBy('m')->pluck('total', 'm');
        $out = [];
        for ($d = $from->copy()->startOfMonth(); $d->lte($to); $d->addMonth()) {
            $k = $d->format('Y-m');
            $out[] = ['month' => $k, 'collected' => (string) Money::fromDb((string) ($byMonth[$k] ?? '0'))->getAmount()];
        }

        return $out;
    }

    /**
     * Commercial documents linked to a project — directly (project_id) or through its source quotation — for the
     * document chain view (M02-02): quotations, client POs / LOAs, invoices, credit notes and the receipts
     * allocated to those invoices.
     *
     * @return array{quotations: list<array<string, mixed>>, client_pos: list<array<string, mixed>>, invoices: list<array<string, mixed>>, credit_notes: list<array<string, mixed>>, receipts: list<array<string, mixed>>}
     */
    public function projectDocuments(int $projectId, ?int $sourceQuotationId = null): array
    {
        $docs = DB::table('commercial_documents')->whereNotIn('status', ['draft'])
            ->where(fn ($q) => $q->where('project_id', $projectId)->when($sourceQuotationId !== null, fn ($w) => $w->orWhere('id', $sourceQuotationId)->orWhere('related_document_id', $sourceQuotationId)))
            ->orderBy('issue_date')->orderBy('id')
            ->get(['id', 'type', 'document_no', 'revision_no', 'status', 'issue_date', 'total', 'amount_received', 'amount_credited', 'external_ref', 'settlement_status']);
        $row = fn ($d): array => ['id' => (int) $d->id, 'type' => $d->type, 'number' => $d->document_no.($d->revision_no > 0 ? ' rev '.$d->revision_no : ''), 'status' => $d->status, 'date' => (string) $d->issue_date,
            'total' => (string) Money::fromDb((string) $d->total)->getAmount(), 'external_ref' => $d->external_ref, 'settlement_status' => $d->settlement_status,
            'outstanding' => (string) Money::fromDb((string) $d->total)->minus(Money::fromDb((string) $d->amount_received))->minus(Money::fromDb((string) $d->amount_credited))->getAmount()];
        $by = fn (string $t) => $docs->where('type', $t)->map($row)->values()->all();
        $invoiceIds = $docs->where('type', 'invoice')->pluck('id')->all();
        $receipts = $invoiceIds === [] ? [] : DB::table('receipt_allocations as a')->join('receipts as r', 'r.id', '=', 'a.receipt_id')->whereIn('a.invoice_id', $invoiceIds)
            ->orderBy('r.receipt_date')->get(['r.id', 'r.receipt_no', 'r.receipt_date', 'r.status', 'a.amount', 'a.invoice_id', 'a.voided_at'])
            ->map(fn ($r): array => ['id' => (int) $r->id, 'number' => $r->receipt_no, 'date' => (string) $r->receipt_date, 'status' => $r->voided_at !== null ? 'void' : $r->status,
                'amount' => (string) Money::fromDb((string) $r->amount)->getAmount(), 'invoice_id' => (int) $r->invoice_id])->values()->all();

        return ['quotations' => $by('quotation'), 'client_pos' => $by('client_po'), 'invoices' => $by('invoice'), 'credit_notes' => $by('credit_note'), 'receipts' => $receipts];
    }
}
