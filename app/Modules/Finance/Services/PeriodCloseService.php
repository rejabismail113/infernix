<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Money\Money;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Reports\FinanceReports;
use Illuminate\Support\Facades\DB;

/**
 * Month-end close checklist (WF-08): unposted drafts, unmatched bank lines, balance check, trial balance
 * preview. Locking itself is FiscalPeriodService (OWN/FIN, MFA re-authentication, audited).
 *
 * @see SRS M01-08, WF-08, UAT-09
 */
final class PeriodCloseService
{
    public function __construct(private readonly FinanceReports $reports) {}

    /** @return array<string, mixed> */
    public function checklist(FiscalPeriod $period): array
    {
        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();
        $count = fn (string $table, string $dateCol, array $statuses): int => DB::table($table)->whereBetween($dateCol, [$from, $to])->whereIn('status', $statuses)->count();

        $balance = DB::table('journal_lines')->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.fiscal_period_id', $period->id)->where('journals.status', '!=', 'draft')
            ->selectRaw('COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c')->first();
        $items = [
            ['key' => 'draft_journals', 'label' => 'Unposted adjusting journals', 'count' => $count('journals', 'posting_date', ['draft'])],
            ['key' => 'draft_transactions', 'label' => 'Draft income/expense entries', 'count' => $count('transactions', 'transaction_date', ['draft'])],
            ['key' => 'pending_bills', 'label' => 'Bills not yet approved', 'count' => $count('bills', 'bill_date', ['draft', 'pending_approval'])],
            ['key' => 'pending_payments', 'label' => 'Payments not yet released', 'count' => $count('payments', 'payment_date', ['draft', 'pending_approval', 'approved'])],
            ['key' => 'pending_claims', 'label' => 'Claims awaiting approval', 'count' => DB::table('claims')->whereIn('status', ['submitted', 'pending_approval'])->whereDate('submitted_at', '<=', $to)->count()],
            ['key' => 'unmatched_bank', 'label' => 'Unmatched bank lines', 'count' => DB::table('bank_transactions')->whereBetween('txn_date', [$from, $to])->where('status', 'unmatched')->count()],
        ];
        $balanced = Money::fromDb((string) $balance->d)->isEqualTo(Money::fromDb((string) $balance->c));

        return [
            'items' => $items,
            'balanced' => $balanced,
            'debits' => Money::toDb(Money::fromDb((string) $balance->d)),
            'credits' => Money::toDb(Money::fromDb((string) $balance->c)),
            'ready' => $balanced && collect($items)->every(fn (array $i): bool => $i['count'] === 0),
            'trial_balance' => $this->reports->trialBalance(null, $period->ends_on),
        ];
    }
}
