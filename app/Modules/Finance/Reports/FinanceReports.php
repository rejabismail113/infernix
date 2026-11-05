<?php

declare(strict_types=1);

namespace App\Modules\Finance\Reports;

use App\Kernel\Money\Money;
use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\ChartAccount;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping reports read straight from posted journals (reversed journals and their reversals both
 * count, so a reversal nets to zero). Every report returns a table shape:
 *   ['title', 'columns' => [[key,label,numeric]], 'rows' => [...], 'totals' => [...], 'notes' => [...]]
 * Amounts are decimal strings. Controllers apply the finance_reports permission and audit exports.
 *
 * @see SRS M01 outputs, §8.1, M11-04, M11-05
 */
final class FinanceReports
{
    /** @return array<string, mixed> */
    public function trialBalance(?Carbon $from, Carbon $to): array
    {
        $rows = [];
        $totD = Money::zero();
        $totC = Money::zero();
        foreach ($this->movements($from, $to) as $acc) {
            $net = $acc['debit']->minus($acc['credit']);
            if ($acc['debit']->isZero() && $acc['credit']->isZero()) {
                continue;
            }
            $d = $net->isPositive() ? $net : Money::zero();
            $c = $net->isNegative() ? $net->negated() : Money::zero();
            $totD = $totD->plus($d);
            $totC = $totC->plus($c);
            $rows[] = ['account_id' => $acc['id'], 'code' => $acc['code'], 'name' => $acc['name'], 'type' => $acc['type'], 'debit' => $this->s($d), 'credit' => $this->s($c)];
        }

        return [
            'title' => 'Trial balance '.($from ? $from->toDateString().' – ' : 'as at ').$to->toDateString(),
            'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Account'], ['key' => 'type', 'label' => 'Type'], ['key' => 'debit', 'label' => 'Debit', 'numeric' => true], ['key' => 'credit', 'label' => 'Credit', 'numeric' => true]],
            'rows' => $rows,
            'totals' => ['debit' => $this->s($totD), 'credit' => $this->s($totC)],
            'balanced' => $totD->isEqualTo($totC),
            'notes' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function generalLedger(ChartAccount $account, Carbon $from, Carbon $to): array
    {
        $opening = $this->balance($account->id, null, $from->copy()->subDay());
        $running = $opening;
        $rows = [];
        $lines = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)->where('journals.status', '!=', 'draft')
            ->whereBetween('journals.posting_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('journals.posting_date')->orderBy('journals.id')->orderBy('journal_lines.line_no')
            ->select('journals.id as journal_id', 'journals.journal_no', 'journals.posting_date', 'journals.source_type', 'journals.source_id', 'journals.status', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.description')->get();
        foreach ($lines as $l) {
            $d = Money::fromDb((string) $l->debit);
            $c = Money::fromDb((string) $l->credit);
            $running = $running->plus($d)->minus($c);
            $rows[] = [
                'date' => (string) $l->posting_date, 'journal_no' => $l->journal_no, 'journal_id' => $l->journal_id, 'source' => $l->source_type.($l->source_id ? ' #'.$l->source_id : ''),
                'source_type' => $l->source_type, 'source_id' => $l->source_id, 'description' => $l->description, 'status' => $l->status,
                'debit' => $this->s($d), 'credit' => $this->s($c), 'balance' => $this->s($running),
            ];
        }

        return [
            'title' => "General ledger — {$account->label()}",
            'columns' => [['key' => 'date', 'label' => 'Date'], ['key' => 'journal_no', 'label' => 'Journal'], ['key' => 'source', 'label' => 'Source'], ['key' => 'description', 'label' => 'Description'], ['key' => 'debit', 'label' => 'Debit', 'numeric' => true], ['key' => 'credit', 'label' => 'Credit', 'numeric' => true], ['key' => 'balance', 'label' => 'Balance', 'numeric' => true]],
            'rows' => $rows,
            'totals' => ['opening' => $this->s($opening), 'closing' => $this->s($running)],
            'notes' => ['Balance = debits − credits (negative = credit balance).'],
        ];
    }

    /**
     * @param  'accrual'|'cash'  $basis  accrual = when posted; cash = income/expense recognised when the money moved
     *                                   (direct bank postings + payable settlements attributed to the settled bill/claim's lines)
     * @return array<string, mixed>
     */
    public function profitAndLoss(Carbon $from, Carbon $to, string $basis = 'accrual'): array
    {
        $byAccount = $basis === 'cash' ? $this->cashBasisMovements($from, $to) : $this->movements($from, $to, [AccountType::Income->value, AccountType::Expense->value]);
        $income = Money::zero();
        $expense = Money::zero();
        $rows = [];
        foreach ($byAccount as $acc) {
            $isIncome = $acc['type'] === AccountType::Income->value;
            $amount = $isIncome ? $acc['credit']->minus($acc['debit']) : $acc['debit']->minus($acc['credit']);
            if ($amount->isZero()) {
                continue;
            }
            $isIncome ? $income = $income->plus($amount) : $expense = $expense->plus($amount);
            $rows[] = ['section' => $isIncome ? 'Income' : 'Expenses', 'code' => $acc['code'], 'name' => $acc['name'], 'amount' => $this->s($amount)];
        }
        usort($rows, fn ($a, $b) => [$a['section'] === 'Expenses', $a['code']] <=> [$b['section'] === 'Expenses', $b['code']]);

        return [
            'title' => 'Profit & loss '.$from->toDateString().' – '.$to->toDateString().' ('.($basis === 'cash' ? 'cash basis' : 'accrual basis').')',
            'columns' => [['key' => 'section', 'label' => 'Section'], ['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Account'], ['key' => 'amount', 'label' => 'Amount', 'numeric' => true]],
            'rows' => $rows,
            'totals' => ['income' => $this->s($income), 'expenses' => $this->s($expense), 'net_profit' => $this->s($income->minus($expense))],
            'notes' => array_values(array_filter([
                'Owner drawings and capital purchases are balance-sheet items and never appear here (M01-10).',
                $basis === 'cash' ? 'Cash basis is indicative and pending accountant confirmation (docs/DECISIONS.md D-10).' : null,
            ])),
        ];
    }

    /** @return array<string, mixed> */
    public function payablesAgeing(Carbon $asOf): array
    {
        $rows = [];
        $buckets = ['current' => Money::zero(), 'd1_30' => Money::zero(), 'd31_60' => Money::zero(), 'd61_90' => Money::zero(), 'd90_plus' => Money::zero()];
        $bills = DB::table('bills')->join('vendors', 'vendors.id', '=', 'bills.vendor_id')->whereIn('bills.status', ['approved', 'partially_paid'])
            ->whereDate('bills.bill_date', '<=', $asOf->toDateString())->orderBy('bills.due_date')
            ->select('bills.id', 'bills.bill_no', 'bills.vendor_invoice_no', 'vendors.name as vendor', 'bills.due_date', 'bills.total_amount', 'bills.paid_amount')->get();
        foreach ($bills as $b) {
            $out = Money::fromDb((string) $b->total_amount)->minus(Money::fromDb((string) $b->paid_amount));
            $days = (int) Carbon::parse((string) $b->due_date)->diffInDays($asOf, false);
            $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'd90_plus')));
            $buckets[$bucket] = $buckets[$bucket]->plus($out);
            $rows[] = ['bill_id' => $b->id, 'bill_no' => $b->bill_no, 'vendor' => $b->vendor, 'vendor_invoice_no' => $b->vendor_invoice_no, 'due_date' => (string) $b->due_date, 'days_overdue' => max(0, $days), 'bucket' => $bucket, 'outstanding' => $this->s($out)];
        }

        return [
            'title' => 'Payables ageing as at '.$asOf->toDateString(),
            'columns' => [['key' => 'bill_no', 'label' => 'Bill'], ['key' => 'vendor', 'label' => 'Vendor'], ['key' => 'vendor_invoice_no', 'label' => 'Vendor invoice'], ['key' => 'due_date', 'label' => 'Due'], ['key' => 'days_overdue', 'label' => 'Days overdue', 'numeric' => true], ['key' => 'bucket', 'label' => 'Bucket'], ['key' => 'outstanding', 'label' => 'Outstanding', 'numeric' => true]],
            'rows' => $rows,
            'totals' => array_map(fn (BrickMoney $m) => $this->s($m), $buckets) + ['total' => $this->s(array_reduce($buckets, fn (BrickMoney $c, BrickMoney $m) => $c->plus($m), Money::zero()))],
            'notes' => [],
        ];
    }

    /**
     * Built on the M03 invoices table (commercial_documents, Prompt 3): outstanding = total − receipt
     * allocations − credit note allocations. Until that module exists the report is empty.
     *
     * @return array<string, mixed>
     */
    public function receivablesAgeing(Carbon $asOf): array
    {
        $columns = [['key' => 'document_no', 'label' => 'Invoice'], ['key' => 'client', 'label' => 'Client'], ['key' => 'due_date', 'label' => 'Due'], ['key' => 'days_overdue', 'label' => 'Days overdue', 'numeric' => true], ['key' => 'bucket', 'label' => 'Bucket'], ['key' => 'outstanding', 'label' => 'Outstanding', 'numeric' => true]];
        if (! Schema::hasTable('commercial_documents') || ! Schema::hasTable('receipt_allocations')) {
            return ['title' => 'Receivables ageing as at '.$asOf->toDateString(), 'columns' => $columns, 'rows' => [], 'totals' => ['total' => '0.00'], 'notes' => ['Invoices arrive with the quotation/invoice module (Prompt 3).']];
        }
        $rows = [];
        $total = Money::zero();
        $invoices = DB::table('commercial_documents as d')->where('d.type', 'invoice')->whereIn('d.status', ['issued', 'accepted'])->whereDate('d.issue_date', '<=', $asOf->toDateString())
            ->selectRaw("d.id, d.document_no, d.counterparty_snapshot->>'name' as client, d.due_date, d.total,
                (SELECT COALESCE(SUM(ra.amount),0) FROM receipt_allocations ra WHERE ra.invoice_id = d.id) as received,
                (SELECT COALESCE(SUM(ca.amount),0) FROM credit_note_allocations ca WHERE ca.invoice_id = d.id) as credited")->get();
        foreach ($invoices as $i) {
            $out = Money::fromDb((string) $i->total)->minus(Money::fromDb((string) $i->received))->minus(Money::fromDb((string) $i->credited));
            if (! $out->isPositive()) {
                continue;
            }
            $days = $i->due_date ? (int) Carbon::parse((string) $i->due_date)->diffInDays($asOf, false) : 0;
            $total = $total->plus($out);
            $rows[] = ['document_no' => $i->document_no, 'client' => $i->client, 'due_date' => (string) $i->due_date, 'days_overdue' => max(0, $days), 'bucket' => $days <= 0 ? 'current' : ($days <= 30 ? 'd1_30' : ($days <= 60 ? 'd31_60' : ($days <= 90 ? 'd61_90' : 'd90_plus'))), 'outstanding' => $this->s($out)];
        }

        return ['title' => 'Receivables ageing as at '.$asOf->toDateString(), 'columns' => $columns, 'rows' => $rows, 'totals' => ['total' => $this->s($total)], 'notes' => []];
    }

    /** @return array<string, mixed> */
    public function cashMovement(Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach (BankAccount::query()->with(['companyAccount', 'glAccount'])->get() as $bank) {
            $opening = $this->balance($bank->gl_account_id, null, $from->copy()->subDay());
            $m = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')->where('journal_lines.account_id', $bank->gl_account_id)
                ->where('journals.status', '!=', 'draft')->whereBetween('journals.posting_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
            $in = Money::fromDb((string) $m->d);
            $out = Money::fromDb((string) $m->c);
            $rows[] = ['bank' => $bank->label(), 'gl' => $bank->glAccount->code, 'opening' => $this->s($opening), 'inflows' => $this->s($in), 'outflows' => $this->s($out), 'closing' => $this->s($opening->plus($in)->minus($out))];
        }

        return [
            'title' => 'Cash movement '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'bank', 'label' => 'Bank account'], ['key' => 'gl', 'label' => 'GL'], ['key' => 'opening', 'label' => 'Opening', 'numeric' => true], ['key' => 'inflows', 'label' => 'In', 'numeric' => true], ['key' => 'outflows', 'label' => 'Out', 'numeric' => true], ['key' => 'closing', 'label' => 'Closing', 'numeric' => true]],
            'rows' => $rows, 'totals' => [], 'notes' => ['Ledger (book) balances; see bank reconciliation for statement balances.'],
        ];
    }

    /**
     * Posted business expenses by category account; owner drawings (equity) are excluded (§8.1).
     *
     * @return array<string, mixed>
     */
    public function expensesByCategory(Carbon $from, Carbon $to): array
    {
        $rows = [];
        $total = Money::zero();
        foreach ($this->movements($from, $to, [AccountType::Expense->value]) as $acc) {
            if ($acc['subtype'] === 'owner_drawings') {
                continue;
            }
            $amount = $acc['debit']->minus($acc['credit']);
            if ($amount->isZero()) {
                continue;
            }
            $total = $total->plus($amount);
            $rows[] = ['code' => $acc['code'], 'category' => $acc['name'], 'amount' => $this->s($amount)];
        }

        return ['title' => 'Expenses by category '.$from->toDateString().' – '.$to->toDateString(), 'columns' => [['key' => 'code', 'label' => 'Code'], ['key' => 'category', 'label' => 'Category'], ['key' => 'amount', 'label' => 'Amount', 'numeric' => true]], 'rows' => $rows, 'totals' => ['total' => $this->s($total)], 'notes' => ['Owner drawings are excluded.']];
    }

    /**
     * Approved claims less reimbursements; pending approval shown separately (§8.1).
     *
     * @return array<string, mixed>
     */
    public function claimsOutstanding(): array
    {
        $rows = [];
        $approvedOut = Money::zero();
        $pending = Money::zero();
        $claims = DB::table('claims')->join('users', 'users.id', '=', 'claims.staff_user_id')->whereIn('claims.status', ['submitted', 'pending_approval', 'finance_payable'])
            ->orderBy('claims.claim_no')->select('claims.id', 'claims.claim_no', 'users.name as staff', 'claims.status', 'claims.total_amount', 'claims.approved_amount', 'claims.paid_amount', 'claims.submitted_at')->get();
        foreach ($claims as $c) {
            $isPending = $c->status !== 'finance_payable';
            $amount = $isPending ? Money::fromDb((string) $c->total_amount) : Money::fromDb((string) $c->approved_amount)->minus(Money::fromDb((string) $c->paid_amount));
            $isPending ? $pending = $pending->plus($amount) : $approvedOut = $approvedOut->plus($amount);
            $rows[] = ['claim_id' => $c->id, 'claim_no' => $c->claim_no, 'staff' => $c->staff, 'status' => $c->status, 'group' => $isPending ? 'Pending approval' : 'Approved, not yet paid', 'amount' => $this->s($amount)];
        }

        return ['title' => 'Claims outstanding', 'columns' => [['key' => 'claim_no', 'label' => 'Claim'], ['key' => 'staff', 'label' => 'Staff'], ['key' => 'group', 'label' => 'Group'], ['key' => 'status', 'label' => 'Status'], ['key' => 'amount', 'label' => 'Amount', 'numeric' => true]], 'rows' => $rows, 'totals' => ['approved_unpaid' => $this->s($approvedOut), 'pending_approval' => $this->s($pending)], 'notes' => []];
    }

    /**
     * Owner capital, drawings and current account (amounts owed to/by the owner).
     *
     * @return array<string, mixed>
     */
    public function ownerStatement(Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach (['owner_capital' => 'Owner capital', 'owner_drawings' => 'Owner drawings', 'owner_current' => 'Owner current account (owed to owner)'] as $key => $label) {
            $acc = ChartAccount::query()->where('system_key', $key)->first();
            if ($acc === null) {
                continue;
            }
            $opening = $this->balance($acc->id, null, $from->copy()->subDay());
            $closing = $this->balance($acc->id, null, $to);
            $sign = $acc->isDebitNormal() ? 1 : -1;
            $rows[] = ['account' => $label, 'code' => $acc->code, 'opening' => $this->s($sign > 0 ? $opening : $opening->negated()), 'movement' => $this->s($sign > 0 ? $closing->minus($opening) : $opening->minus($closing)), 'closing' => $this->s($sign > 0 ? $closing : $closing->negated())];
        }

        return ['title' => 'Owner capital & drawings '.$from->toDateString().' – '.$to->toDateString(), 'columns' => [['key' => 'account', 'label' => 'Account'], ['key' => 'code', 'label' => 'Code'], ['key' => 'opening', 'label' => 'Opening', 'numeric' => true], ['key' => 'movement', 'label' => 'Movement', 'numeric' => true], ['key' => 'closing', 'label' => 'Closing', 'numeric' => true]], 'rows' => $rows, 'totals' => [], 'notes' => ['Amounts shown in each account\'s normal direction. PLACEHOLDER treatment pending accountant review (D-12).']];
    }

    /** Net balance (debit − credit) of an account up to $to (inclusive), optionally from $from. */
    public function balance(int $accountId, ?Carbon $from, Carbon $to): BrickMoney
    {
        $q = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')->where('journal_lines.account_id', $accountId)
            ->where('journals.status', '!=', 'draft')->where('journals.posting_date', '<=', $to->toDateString());
        if ($from) {
            $q->where('journals.posting_date', '>=', $from->toDateString());
        }
        $r = $q->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS b')->first();

        return Money::fromDb((string) ($r->b ?? '0'));
    }

    /**
     * @param  list<string>|null  $types
     * @return list<array{id: int, code: string, name: string, type: string, subtype: string|null, debit: BrickMoney, credit: BrickMoney}>
     */
    private function movements(?Carbon $from, Carbon $to, ?array $types = null): array
    {
        $q = DB::table('chart_accounts as a')
            ->leftJoin('journal_lines as l', 'l.account_id', '=', 'a.id')
            ->leftJoin('journals as j', function ($join) use ($from, $to): void {
                $join->on('j.id', '=', 'l.journal_id')->where('j.status', '!=', 'draft')->where('j.posting_date', '<=', $to->toDateString());
                if ($from) {
                    $join->where('j.posting_date', '>=', $from->toDateString());
                }
            })
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.subtype')->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, a.subtype, COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit END),0) AS d, COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.credit END),0) AS c');
        if ($types) {
            $q->whereIn('a.type', $types);
        }

        return $q->get()->map(fn ($r): array => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'subtype' => $r->subtype, 'debit' => Money::fromDb((string) $r->d), 'credit' => Money::fromDb((string) $r->c)])->all();
    }

    /** @return list<array{id: int, code: string, name: string, type: string, subtype: string|null, debit: BrickMoney, credit: BrickMoney}> */
    private function cashBasisMovements(Carbon $from, Carbon $to): array
    {
        /** @var array<int, array{id: int, code: string, name: string, type: string, subtype: string|null, debit: BrickMoney, credit: BrickMoney}> $acc */
        $acc = [];
        $add = function (object $line, BrickMoney $debit, BrickMoney $credit) use (&$acc): void {
            $acc[$line->account_id] ??= ['id' => (int) $line->account_id, 'code' => $line->code, 'name' => $line->name, 'type' => $line->type, 'subtype' => $line->subtype, 'debit' => Money::zero(), 'credit' => Money::zero()];
            $acc[$line->account_id]['debit'] = $acc[$line->account_id]['debit']->plus($debit);
            $acc[$line->account_id]['credit'] = $acc[$line->account_id]['credit']->plus($credit);
        };
        $plLines = fn () => DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('chart_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.type', ['income', 'expense'])->where('j.status', '!=', 'draft')
            ->select('l.account_id', 'l.debit', 'l.credit', 'a.code', 'a.name', 'a.type', 'a.subtype', 'j.id as journal_id', 'j.source_type', 'j.source_id');

        // 1) P&L lines in journals that move a bank account directly (cash sales, direct expenses) in range.
        $bankGl = BankAccount::query()->pluck('gl_account_id')->all();
        $direct = $plLines()->whereBetween('j.posting_date', [$from->toDateString(), $to->toDateString()])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_lines as b')->whereColumn('b.journal_id', 'j.id')->whereIn('b.account_id', $bankGl ?: [0]))->get();
        foreach ($direct as $l) {
            $add($l, Money::fromDb((string) $l->debit), Money::fromDb((string) $l->credit));
        }

        // 2) Payments in range: attribute each allocation to the settled bill/claim's P&L lines pro rata.
        $allocs = DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')->where('p.status', 'paid')
            ->whereBetween('p.payment_date', [$from->toDateString(), $to->toDateString()])->select('pa.target_type', 'pa.target_id', 'pa.amount')->get();
        foreach ($allocs as $a) {
            $target = DB::table($a->target_type === 'bill' ? 'bills' : 'claims')->where('id', $a->target_id)->first();
            if ($target === null || $target->journal_id === null) {
                continue;
            }
            $base = Money::fromDb((string) ($a->target_type === 'bill' ? $target->total_amount : $target->approved_amount));
            if ($base->isZero()) {
                continue;
            }
            $ratio = BigDecimal::of(Money::toDb(Money::fromDb((string) $a->amount)))->dividedBy(Money::toDb($base), 10, RoundingMode::HALF_UP);
            foreach ($plLines()->where('j.id', $target->journal_id)->get() as $l) {
                $d = BrickMoney::of(BigDecimal::of((string) $l->debit)->multipliedBy($ratio)->toScale(2, RoundingMode::HALF_UP), 'MYR');
                $c = BrickMoney::of(BigDecimal::of((string) $l->credit)->multipliedBy($ratio)->toScale(2, RoundingMode::HALF_UP), 'MYR');
                $add($l, $d, $c);
            }
        }
        ksort($acc);

        return array_values($acc);
    }

    private function s(BrickMoney $m): string
    {
        return (string) $m->getAmount();
    }
}
