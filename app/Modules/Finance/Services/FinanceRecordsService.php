<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Finance\Exceptions\PostingRejected;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * M01 implementation of the kernel FinanceRecords contract. Read models + the finance_links table; it never
 * posts, so linking an operational record can never create a second expense.
 *
 * @see SRS M05-01, M07-02, M08-02, M09-04, M10-02, M10-05, WF-06, UAT-03, UAT-12
 */
final class FinanceRecordsService implements FinanceRecords
{
    private const CLAIM_BOOKED = ['approved', 'finance_payable', 'paid', 'closed'];

    private const BILL_BOOKED = ['approved', 'partially_paid', 'paid'];

    public function snapshot(string $type, int $id): ?array
    {
        return $this->records($type, [$id])[0] ?? null;
    }

    public function search(string $type, string $q, int $limit = 20): array
    {
        $q = trim($q);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $ids = match ($type) {
            'transaction' => DB::table('transactions')->where('status', 'posted')
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('transaction_no', 'ilike', $like)->orWhere('description', 'ilike', $like)->orWhere('counterparty_name', 'ilike', $like)))
                ->orderByDesc('transaction_date')->limit($limit)->pluck('id'),
            'bill' => DB::table('bills')->join('vendors', 'vendors.id', '=', 'bills.vendor_id')->whereIn('bills.status', self::BILL_BOOKED)
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('bill_no', 'ilike', $like)->orWhere('vendor_invoice_no', 'ilike', $like)->orWhere('vendors.name', 'ilike', $like)))
                ->orderByDesc('bill_date')->limit($limit)->pluck('bills.id'),
            'claim' => DB::table('claims')->whereIn('status', self::CLAIM_BOOKED)
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('claim_no', 'ilike', $like)->orWhere('purpose', 'ilike', $like)))
                ->orderByDesc('id')->limit($limit)->pluck('id'),
            'payment' => DB::table('payments')->where('status', 'paid')
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('payment_no', 'ilike', $like)->orWhere('payee_name', 'ilike', $like)->orWhere('reference', 'ilike', $like)))
                ->orderByDesc('payment_date')->limit($limit)->pluck('id'),
            default => throw new PostingRejected("Unknown finance record type [{$type}]."),
        };

        return $this->records($type, $ids->map(fn ($i): int => (int) $i)->all());
    }

    public function link(string $entityType, int $entityId, string $sourceType, int $sourceId, string $purpose, User $actor, ?string $note = null): void
    {
        $record = $this->snapshot($sourceType, $sourceId) ?? throw new PostingRejected('That finance record does not exist.');
        if (! $this->isBooked($record)) {
            throw new PostingRejected("{$record['number']} is not booked yet (status {$record['status']}); link it once it is posted/approved.");
        }
        $inserted = DB::table('finance_links')->insertOrIgnore([
            'entity_type' => $entityType, 'entity_id' => $entityId, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'purpose' => $purpose, 'note' => $note, 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        if ($inserted === 0) {
            throw new PostingRejected("{$record['number']} is already linked here.");
        }
        Audit::log('link_finance', $entityType, $entityId, null, ['source' => "{$sourceType}:{$sourceId}", 'number' => $record['number'], 'purpose' => $purpose], $note, $actor);
    }

    public function unlink(string $entityType, int $entityId, string $sourceType, int $sourceId, User $actor): void
    {
        $deleted = DB::table('finance_links')->where(['entity_type' => $entityType, 'entity_id' => $entityId, 'source_type' => $sourceType, 'source_id' => $sourceId])->delete();
        if ($deleted > 0) {
            Audit::log('unlink_finance', $entityType, $entityId, ['source' => "{$sourceType}:{$sourceId}"], null, null, $actor);
        }
    }

    public function linked(string $entityType, int $entityId): array
    {
        $links = DB::table('finance_links')->where('entity_type', $entityType)->where('entity_id', $entityId)->orderBy('id')->get();
        $out = [];
        foreach ($links as $l) {
            $snap = $this->snapshot((string) $l->source_type, (int) $l->source_id);
            if ($snap !== null) {
                $out[] = $snap + ['purpose' => (string) $l->purpose, 'note' => $l->note];
            }
        }

        return $out;
    }

    public function linkedTotals(string $entityType, array $entityIds, ?string $purpose = null): array
    {
        $totals = array_fill_keys($entityIds, '0.00');
        if ($entityIds === []) {
            return $totals;
        }
        $links = DB::table('finance_links')->where('entity_type', $entityType)->whereIn('entity_id', $entityIds)->when($purpose !== null, fn ($q) => $q->where('purpose', $purpose))->get();
        $byType = [];
        foreach ($links as $l) {
            $byType[(string) $l->source_type][] = (int) $l->source_id;
        }
        $amounts = [];
        foreach ($byType as $type => $ids) {
            foreach ($this->records($type, array_values(array_unique($ids))) as $r) {
                $amounts["{$type}:{$r['id']}"] = $r['amount'];
            }
        }
        foreach ($links as $l) {
            $amount = $amounts["{$l->source_type}:{$l->source_id}"] ?? '0';
            $totals[(int) $l->entity_id] = (string) BigDecimal::of($totals[(int) $l->entity_id])->plus($amount)->toScale(2);
        }

        return $totals;
    }

    public function activityLines(int $activityId): array
    {
        $rows = [];
        foreach (DB::table('transaction_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('t.status', 'posted')->where('t.direction', 'outflow')
            ->where(fn ($q) => $q->where('l.activity_id', $activityId)->orWhere(fn ($w) => $w->whereNull('l.activity_id')->where('t.activity_id', $activityId)))
            ->get(['l.id', 't.id as tid', 't.transaction_no', 't.transaction_date', 'l.description', 'l.amount', 't.capitalization_class', 'l.project_id', 't.project_id as tproject']) as $r) {
            $rows[] = ['line_type' => 'transaction_line', 'line_id' => (int) $r->id, 'source_type' => 'transaction', 'source_id' => (int) $r->tid, 'number' => (string) $r->transaction_no,
                'date' => (string) $r->transaction_date, 'description' => (string) $r->description, 'kind' => strtolower((string) $r->capitalization_class) === 'expense' ? 'expense' : strtolower((string) $r->capitalization_class),
                'amount' => self::dec((string) $r->amount), 'project_id' => $r->project_id ?? $r->tproject, 'url' => route('finance.transactions.show', (int) $r->tid)];
        }
        foreach (DB::table('claim_lines as l')->join('claims as c', 'c.id', '=', 'l.claim_id')->join('claim_types as ct', 'ct.id', '=', 'l.claim_type_id')
            ->whereIn('c.status', self::CLAIM_BOOKED)->where('l.status', 'approved')
            ->where(fn ($q) => $q->where('l.activity_id', $activityId)->orWhere(fn ($w) => $w->whereNull('l.activity_id')->where('c.activity_id', $activityId)))
            ->get(['l.id', 'c.id as cid', 'c.claim_no', 'l.incurred_on', 'l.description', 'l.amount', 'ct.code', 'ct.name', 'l.project_id', 'c.project_id as cproject']) as $r) {
            $rows[] = ['line_type' => 'claim_line', 'line_id' => (int) $r->id, 'source_type' => 'claim', 'source_id' => (int) $r->cid, 'number' => (string) $r->claim_no,
                'date' => (string) $r->incurred_on, 'description' => (string) $r->description, 'kind' => (string) $r->code, 'amount' => self::dec((string) $r->amount),
                'project_id' => $r->project_id ?? $r->cproject, 'url' => route('finance.claims.show', (int) $r->cid)];
        }
        foreach (DB::table('bill_lines as l')->join('bills as b', 'b.id', '=', 'l.bill_id')->join('vendors as v', 'v.id', '=', 'b.vendor_id')
            ->whereIn('b.status', self::BILL_BOOKED)->where('l.activity_id', $activityId)
            ->get(['l.id', 'b.id as bid', 'b.bill_no', 'b.bill_date', 'l.description', 'l.amount', 'v.name', 'l.project_id']) as $r) {
            $rows[] = ['line_type' => 'bill_line', 'line_id' => (int) $r->id, 'source_type' => 'bill', 'source_id' => (int) $r->bid, 'number' => (string) $r->bill_no,
                'date' => (string) $r->bill_date, 'description' => "{$r->name}: {$r->description}", 'kind' => 'vendor_bill', 'amount' => self::dec((string) $r->amount),
                'project_id' => $r->project_id, 'url' => route('finance.bills.show', (int) $r->bid)];
        }
        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['number']] <=> [$b['date'], $b['number']]);

        return $rows;
    }

    public function taggableLines(string $q, int $limit = 20): array
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)).'%';
        $out = [];
        foreach (DB::table('transaction_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')->where('t.status', 'posted')->where('t.direction', 'outflow')
            ->where(fn ($w) => $w->where('t.transaction_no', 'ilike', $like)->orWhere('l.description', 'ilike', $like))->orderByDesc('t.transaction_date')->limit($limit)
            ->get(['l.id', 't.transaction_no', 't.transaction_date', 'l.description', 'l.amount', 'l.activity_id', 't.activity_id as tact']) as $r) {
            $out[] = ['line_type' => 'transaction_line', 'line_id' => (int) $r->id, 'number' => (string) $r->transaction_no, 'date' => (string) $r->transaction_date, 'description' => (string) $r->description,
                'amount' => self::dec((string) $r->amount), 'activity_id' => $r->activity_id ?? $r->tact];
        }
        foreach (DB::table('claim_lines as l')->join('claims as c', 'c.id', '=', 'l.claim_id')->whereIn('c.status', self::CLAIM_BOOKED)->where('l.status', 'approved')
            ->where(fn ($w) => $w->where('c.claim_no', 'ilike', $like)->orWhere('l.description', 'ilike', $like))->orderByDesc('l.incurred_on')->limit($limit)
            ->get(['l.id', 'c.claim_no', 'l.incurred_on', 'l.description', 'l.amount', 'l.activity_id', 'c.activity_id as cact']) as $r) {
            $out[] = ['line_type' => 'claim_line', 'line_id' => (int) $r->id, 'number' => (string) $r->claim_no, 'date' => (string) $r->incurred_on, 'description' => (string) $r->description,
                'amount' => self::dec((string) $r->amount), 'activity_id' => $r->activity_id ?? $r->cact];
        }
        foreach (DB::table('bill_lines as l')->join('bills as b', 'b.id', '=', 'l.bill_id')->whereIn('b.status', self::BILL_BOOKED)
            ->where(fn ($w) => $w->where('b.bill_no', 'ilike', $like)->orWhere('l.description', 'ilike', $like))->orderByDesc('b.bill_date')->limit($limit)
            ->get(['l.id', 'b.bill_no', 'b.bill_date', 'l.description', 'l.amount', 'l.activity_id']) as $r) {
            $out[] = ['line_type' => 'bill_line', 'line_id' => (int) $r->id, 'number' => (string) $r->bill_no, 'date' => (string) $r->bill_date, 'description' => (string) $r->description,
                'amount' => self::dec((string) $r->amount), 'activity_id' => $r->activity_id];
        }

        return array_map(fn (array $r): array => ['activity_id' => $r['activity_id'] === null ? null : (int) $r['activity_id']] + $r, $out);
    }

    public function tagActivity(string $lineType, int $lineId, ?int $activityId, User $actor): void
    {
        [$table, $parent, $parentTable, $booked] = match ($lineType) {
            'transaction_line' => ['transaction_lines', 'transaction_id', 'transactions', fn ($p): bool => $p->status === 'posted' && $p->direction === 'outflow'],
            'claim_line' => ['claim_lines', 'claim_id', 'claims', fn ($p): bool => in_array($p->status, self::CLAIM_BOOKED, true)],
            'bill_line' => ['bill_lines', 'bill_id', 'bills', fn ($p): bool => in_array($p->status, self::BILL_BOOKED, true)],
            default => throw new PostingRejected("Unknown line type [{$lineType}]."),
        };
        DB::transaction(function () use ($table, $parent, $parentTable, $booked, $lineType, $lineId, $activityId, $actor): void {
            $line = DB::table($table)->where('id', $lineId)->lockForUpdate()->first() ?? throw new PostingRejected('That finance line does not exist.');
            $p = DB::table($parentTable)->where('id', $line->{$parent})->first();
            if ($p === null || ! $booked($p)) {
                throw new PostingRejected('Only booked (posted / approved) cost lines can be linked to an activity.');
            }
            $current = $line->activity_id ?? ($p->activity_id ?? null);
            if ($activityId !== null && $current !== null && (int) $current !== $activityId) {
                throw new PostingRejected('This cost is already linked to another activity; a cost is counted in one activity only (M07-02).');
            }
            DB::table($table)->where('id', $lineId)->update(['activity_id' => $activityId]);
            Audit::log($activityId === null ? 'untag_activity' : 'tag_activity', $lineType, $lineId, ['activity_id' => $current], ['activity_id' => $activityId], null, $actor);
        });
    }

    public function billsForVendorPo(int $vendorPoId): array
    {
        $bills = DB::table('bills')->where('vendor_po_id', $vendorPoId)->orderBy('bill_date')->get();
        $out = [];
        foreach ($bills as $b) {
            $payments = DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->where('a.target_type', 'bill')->where('a.target_id', $b->id)->where('p.status', '!=', 'cancelled')
                ->get(['p.id', 'p.payment_no', 'p.payment_date', 'a.amount', 'p.status'])
                ->map(fn ($p): array => ['id' => (int) $p->id, 'number' => (string) $p->payment_no, 'date' => (string) $p->payment_date, 'amount' => self::dec((string) $p->amount), 'status' => (string) $p->status, 'url' => route('finance.payments.show', (int) $p->id)])->all();
            $out[] = ['id' => (int) $b->id, 'number' => (string) $b->bill_no, 'vendor_invoice_no' => (string) $b->vendor_invoice_no, 'date' => (string) $b->bill_date, 'amount' => self::dec((string) $b->total_amount),
                'paid' => self::dec((string) $b->paid_amount), 'status' => (string) $b->status, 'url' => route('finance.bills.show', (int) $b->id), 'payments' => $payments];
        }

        return $out;
    }

    public function vendorSpending(string $from, string $to): array
    {
        return DB::table('bills as b')->join('vendors as v', 'v.id', '=', 'b.vendor_id')->whereIn('b.status', self::BILL_BOOKED)->whereBetween('b.bill_date', [$from, $to])
            ->groupBy('v.id', 'v.name')->orderByRaw('sum(b.total_amount) desc')
            ->get(['v.id', 'v.name', DB::raw('count(*) as bills'), DB::raw('sum(b.total_amount) as billed'), DB::raw('sum(b.paid_amount) as paid')])
            ->map(fn ($r): array => ['vendor_id' => (int) $r->id, 'vendor' => (string) $r->name, 'bills' => (int) $r->bills, 'billed' => self::dec((string) $r->billed),
                'paid' => self::dec((string) $r->paid), 'outstanding' => (string) Money::fromDb((string) $r->billed)->minus(Money::fromDb((string) $r->paid))->getAmount()])->all();
    }

    public function journalFor(string $sourceType, int $sourceId): ?array
    {
        $j = DB::table('journals')->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', 'posted')->orderByDesc('id')->first(['id', 'journal_no']);

        return $j === null ? null : ['id' => (int) $j->id, 'journal_no' => (string) $j->journal_no];
    }

    private static function dec(string $v): string
    {
        return (string) Money::fromDb($v)->getAmount();
    }

    /** @param array{status: string, type: string} $r */
    private function isBooked(array $r): bool
    {
        return match ($r['type']) {
            'transaction' => $r['status'] === 'posted',
            'bill' => in_array($r['status'], self::BILL_BOOKED, true),
            'claim' => in_array($r['status'], self::CLAIM_BOOKED, true),
            'payment' => $r['status'] === 'paid',
            default => false,
        };
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{type: string, id: int, number: string, date: string, amount: string, status: string, party: string|null, description: string|null, url: string}>
     */
    private function records(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = fn ($r, string $no, string $date, string $amount, ?string $party, ?string $desc, string $route): array => [
            'type' => $type, 'id' => (int) $r->id, 'number' => $no, 'date' => $date, 'amount' => self::dec($amount), 'status' => (string) $r->status,
            'party' => $party, 'description' => $desc, 'url' => route($route, (int) $r->id),
        ];
        $rows = match ($type) {
            'transaction' => DB::table('transactions')->whereIn('id', $ids)->get()->map(fn ($r) => $map($r, (string) $r->transaction_no, (string) $r->transaction_date, (string) $r->gross_amount, $r->counterparty_name, $r->description, 'finance.transactions.show')),
            'bill' => DB::table('bills')->join('vendors', 'vendors.id', '=', 'bills.vendor_id')->whereIn('bills.id', $ids)->get(['bills.*', 'vendors.name as vendor_name'])
                ->map(fn ($r) => $map($r, (string) $r->bill_no, (string) $r->bill_date, (string) $r->total_amount, (string) $r->vendor_name, trim(((string) $r->vendor_invoice_no).' '.($r->description ?? '')), 'finance.bills.show')),
            'claim' => DB::table('claims')->join('users', 'users.id', '=', 'claims.staff_user_id')->whereIn('claims.id', $ids)->get(['claims.*', 'users.name as staff_name'])
                ->map(fn ($r) => $map($r, (string) $r->claim_no, substr((string) ($r->approved_at ?? $r->created_at), 0, 10), (string) $r->approved_amount, (string) $r->staff_name, $r->purpose, 'finance.claims.show')),
            'payment' => DB::table('payments')->whereIn('id', $ids)->get()->map(fn ($r) => $map($r, (string) $r->payment_no, (string) $r->payment_date, (string) $r->amount, (string) $r->payee_name, $r->memo, 'finance.payments.show')),
            default => throw new PostingRejected("Unknown finance record type [{$type}]."),
        };
        $byId = $rows->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?array => $byId[$id] ?? null, $ids)));
    }
}
