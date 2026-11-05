<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\SecretRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Accountant tax preparation pack (ZIP of CSVs): journals, chart of accounts, payment vouchers, evidence
 * list (receipts/invoices linked in the Document Centre), invoice list, bank movement and review flags.
 * Every cell passes the SecretRedactor; the export is audited. Not a tax filing (SRS §2.2).
 *
 * @see SRS M01-09, §8.1 (Tax preparation pack), M13-05, UAT-08
 */
final class TaxPackService
{
    public function __construct(private readonly SecretRedactor $secrets, private readonly FinanceReports $reports) {}

    public function build(int $year, User $actor): string
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->startOfDay();
        $path = tempnam(sys_get_temp_dir(), 'taxpack').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create ZIP.');
        }

        $this->csv($zip, 'chart_of_accounts.csv', ['code', 'name', 'type', 'subtype', 'tax_treatment_default', 'active', 'notes'],
            DB::table('chart_accounts')->orderBy('code')->get()->map(fn ($a) => [$a->code, $a->name, $a->type, $a->subtype, $a->tax_treatment_default, $a->active ? 'yes' : 'no', $a->notes]));

        $this->csv($zip, 'journals.csv', ['journal_no', 'posting_date', 'status', 'source', 'memo', 'line', 'account_code', 'account', 'debit', 'credit', 'description', 'reversal_of', 'reversed_by'],
            DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('chart_accounts as a', 'a.id', '=', 'l.account_id')
                ->leftJoin('journals as ro', 'ro.id', '=', 'j.reversal_of_id')->leftJoin('journals as rb', 'rb.id', '=', 'j.reversed_by_id')
                ->where('j.status', '!=', 'draft')->whereBetween('j.posting_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('j.posting_date')->orderBy('j.id')->orderBy('l.line_no')
                ->select('j.journal_no', 'j.posting_date', 'j.status', 'j.source_type', 'j.source_id', 'j.memo', 'l.line_no', 'a.code', 'a.name', 'l.debit', 'l.credit', 'l.description', 'ro.journal_no as rof', 'rb.journal_no as rby')->get()
                ->map(fn ($r) => [$r->journal_no, $r->posting_date, $r->status, $r->source_type.'#'.$r->source_id, $r->memo, $r->line_no, $r->code, $r->name, $r->debit, $r->credit, $r->description, $r->rof, $r->rby]));

        $tb = $this->reports->trialBalance(null, $to);
        /** @var list<array<string, string>> $tbRows */
        $tbRows = $tb['rows'];
        $tbCsv = array_map(fn (array $r): array => [$r['code'], $r['name'], $r['type'], $r['debit'], $r['credit']], $tbRows);
        $tbCsv[] = ['', 'TOTAL', '', $tb['totals']['debit'], $tb['totals']['credit']];
        $this->csv($zip, 'trial_balance.csv', ['code', 'account', 'type', 'debit', 'credit'], $tbCsv);

        $this->csv($zip, 'payment_vouchers.csv', ['payment_no', 'date', 'payee', 'method', 'reference', 'amount', 'status', 'journal_no', 'owner_override', 'voucher_document_id'],
            DB::table('payments as p')->leftJoin('journals as j', 'j.id', '=', 'p.journal_id')->whereBetween('p.payment_date', [$from->toDateString(), $to->toDateString()])->orderBy('p.payment_no')
                ->select('p.*', 'j.journal_no')->get()->map(fn ($p) => [$p->payment_no, $p->payment_date, $p->payee_name, $p->method, $p->reference, $p->amount, $p->status, $p->journal_no, $p->owner_override ? 'yes' : 'no', $p->voucher_document_id]));

        $this->csv($zip, 'evidence_documents.csv', ['entity_type', 'entity_id', 'link_type', 'document_id', 'title', 'file_name', 'sha256', 'uploaded_at'],
            DB::table('entity_document_links as l')->join('documents as d', 'd.id', '=', 'l.document_id')
                ->leftJoin('document_versions as v', fn ($j) => $j->on('v.document_id', '=', 'd.id')->whereColumn('v.version_no', 'd.current_version_no'))
                ->whereIn('l.entity_type', ['transaction', 'claim', 'claim_line', 'bill', 'payment', 'bank_account', 'commercial_document'])->whereNull('d.hard_deleted_at')
                ->orderBy('l.entity_type')->orderBy('l.entity_id')->select('l.entity_type', 'l.entity_id', 'l.link_type', 'd.id', 'd.title', 'v.file_name', 'v.content_hash', 'd.uploaded_at')->get()
                ->map(fn ($r) => [$r->entity_type, $r->entity_id, $r->link_type, $r->id, $r->title, $r->file_name, $r->content_hash, $r->uploaded_at]));

        if (Schema::hasTable('commercial_documents')) {
            $this->csv($zip, 'invoices.csv', ['document_no', 'issue_date', 'status', 'total', 'pdf_document_id'],
                DB::table('commercial_documents')->where('type', 'invoice')->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])->orderBy('document_no')->get()
                    ->map(fn ($i) => [$i->document_no, $i->issue_date, $i->status, $i->total, $i->pdf_document_id ?? null]));
        } else {
            $this->csv($zip, 'invoices.csv', ['note'], [['Invoices arrive with the quotation/invoice module (Prompt 3).']]);
        }

        $this->csv($zip, 'bank_movement.csv', ['bank_gl', 'date', 'description', 'reference', 'amount', 'status'],
            DB::table('bank_transactions as t')->join('bank_accounts as b', 'b.id', '=', 't.bank_account_id')->join('chart_accounts as a', 'a.id', '=', 'b.gl_account_id')
                ->whereBetween('t.txn_date', [$from->toDateString(), $to->toDateString()])->orderBy('t.txn_date')->select('a.code', 't.*')->get()
                ->map(fn ($t) => [$t->code, $t->txn_date, $t->description, $t->reference, $t->amount, $t->status]));

        $flags = DB::table('transactions')->where('status', 'posted')->where('tax_treatment', 'pending_review')->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->get()->map(fn ($t) => ['transaction', $t->transaction_no, $t->transaction_date, $t->gross_amount, 'tax treatment pending accountant review'])
            ->concat(DB::table('bills')->whereIn('status', ['approved', 'partially_paid', 'paid'])->where('tax_treatment', 'pending_review')->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()])
                ->get()->map(fn ($b) => ['bill', $b->bill_no, $b->bill_date, $b->total_amount, 'tax treatment pending accountant review']))
            ->concat(DB::table('chart_accounts')->where('notes', 'like', 'PLACEHOLDER%')->get()->map(fn ($a) => ['account', $a->code, '', '', 'chart of accounts mapping is a PLACEHOLDER pending accountant approval']));
        $this->csv($zip, 'review_flags.csv', ['kind', 'reference', 'date', 'amount', 'flag'], $flags);

        $zip->addFromString('README.txt', $this->secrets->scrub("Infernix One — tax preparation pack {$year}\nGenerated ".now()->toIso8601String()." by {$actor->name}.\nBookkeeping output for accountant review; not a tax filing (SRS §2.2).\nAmounts in MYR. Evidence files are listed by Document Centre id and SHA-256; download them through Infernix One.\n"));
        $zip->close();
        Audit::export('tax_pack', ['year' => $year], $actor);

        return $path;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function csv(ZipArchive $zip, string $name, array $headers, iterable $rows): void
    {
        $h = fopen('php://temp', 'r+');
        if ($h === false) {
            return;
        }
        fputcsv($h, $headers);
        foreach ($rows as $row) {
            fputcsv($h, array_map(fn ($v): string => $this->secrets->scrub((string) ($v ?? '')), $row));
        }
        rewind($h);
        $zip->addFromString($name, (string) stream_get_contents($h));
        fclose($h);
    }
}
