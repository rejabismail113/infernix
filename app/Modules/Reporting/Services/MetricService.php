<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Modules\Commercial\Reports\CommercialReports;
use App\Modules\Finance\Reports\FinanceReports;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * Owner and Finance dashboards. Every number is a registered metric (report_metrics) computed server-side from
 * the SAME report functions the report pages and exports use, so a tile never disagrees with its report
 * (consistency test). Each metric checks its own security scope (M11-05); money is returned as decimal strings.
 *
 * @see SRS M11-01, M11-02, M11-04, M11-05
 */
final class MetricService
{
    /** metric_id => definition (seeded into report_metrics). */
    public const DEFINITIONS = [
        'revenue_invoiced' => ['name' => 'Revenue (invoiced / accrued)', 'definition' => 'Income recognised in the period on the accrual basis: issued invoices less credit notes, plus other posted income.', 'source' => 'journal_lines (income accounts) via Profit & loss', 'rule' => 'P&L accrual totals.income', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'cash_received' => ['name' => 'Cash received', 'definition' => 'Money into company bank accounts in the period (book).', 'source' => 'journal_lines (bank GL accounts) via Cash movement', 'rule' => 'Cash movement totals.inflows', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'costs_incurred' => ['name' => 'Costs incurred', 'definition' => 'Business expenses recognised in the period (accrual), excluding owner drawings.', 'source' => 'journal_lines (expense accounts) via Profit & loss', 'rule' => 'P&L accrual totals.expenses', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'cash_paid' => ['name' => 'Cash paid', 'definition' => 'Money out of company bank accounts in the period (book).', 'source' => 'journal_lines (bank GL accounts) via Cash movement', 'rule' => 'Cash movement totals.outflows', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'cash_balance' => ['name' => 'Cash balance by bank account', 'definition' => 'Book balance of each bank account at the period end.', 'source' => 'Cash movement', 'rule' => 'Cash movement rows.closing; total = totals.closing', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'outstanding_invoices' => ['name' => 'Outstanding invoices', 'definition' => 'Issued invoices not yet settled by receipts or credit notes, at the period end.', 'source' => 'commercial_documents, receipt_allocations, credit_note_allocations via Receivables ageing', 'rule' => 'Receivables ageing totals.total; count = rows', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'claims_pending' => ['name' => 'Claims pending approval', 'definition' => 'Submitted claims awaiting approval.', 'source' => 'claims via Claims outstanding', 'rule' => 'Claims outstanding totals.pending_approval', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'claims_approved_unpaid' => ['name' => 'Claims approved, unpaid', 'definition' => 'Approved claims not yet paid in full.', 'source' => 'claims via Claims outstanding', 'rule' => 'Claims outstanding totals.approved_unpaid', 'owner' => 'FIN', 'scope' => 'dashboard_owner:view'],
        'top_projects' => ['name' => 'Top projects by contribution', 'definition' => 'Revenue less costs of journal lines tagged to a project (placeholder until M02 costing in P2).', 'source' => 'journal_lines.project_id', 'rule' => 'SUM(income) − SUM(expense) per project, top 5', 'owner' => 'OWN', 'scope' => 'dashboard_owner:view'],
        'monthly_pnl' => ['name' => 'Monthly profit & loss', 'definition' => 'Income, expenses and net profit per month (accrual).', 'source' => 'Profit & loss per month', 'rule' => 'P&L accrual for each calendar month', 'owner' => 'FIN', 'scope' => 'dashboard_finance:view'],
        'expense_by_category' => ['name' => 'Expenses by category', 'definition' => 'Posted business expenses by category account, owner drawings excluded.', 'source' => 'Expenses by category', 'rule' => 'Expenses by category rows', 'owner' => 'FIN', 'scope' => 'dashboard_finance:view'],
        'unpaid_bills' => ['name' => 'Unpaid bills', 'definition' => 'Approved vendor bills not yet paid, by age bucket.', 'source' => 'bills via Payables ageing', 'rule' => 'Payables ageing totals', 'owner' => 'FIN', 'scope' => 'dashboard_finance:view'],
        'collections' => ['name' => 'Collections', 'definition' => 'Cash collected through official receipts per month.', 'source' => 'receipts (posted)', 'rule' => 'SUM(receipts.amount) per month', 'owner' => 'FIN', 'scope' => 'dashboard_finance:view'],
        'reconciliation_status' => ['name' => 'Reconciliation status', 'definition' => 'Per bank: statement vs book balance and unmatched statement lines.', 'source' => 'bank_transactions, journal_lines', 'rule' => 'Bank reconciliation status report', 'owner' => 'FIN', 'scope' => 'dashboard_finance:view'],
    ];

    public function __construct(
        private readonly FinanceReports $finance,
        private readonly CommercialReports $commercial,
        private readonly PermissionService $permissions,
        private readonly ProjectDirectory $projects,
    ) {}

    /** @return array<string, mixed> */
    public function owner(User $user, Carbon $from, Carbon $to): array
    {
        $this->authorizeScope($user, 'dashboard_owner');
        $pnl = $this->finance->profitAndLoss($from, $to, 'accrual');
        $cash = $this->finance->cashMovement($from, $to);
        $ar = $this->finance->receivablesAgeing($to);
        $claims = $this->finance->claimsOutstanding();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'tiles' => [
                $this->tile('revenue_invoiced', $pnl['totals']['income']),
                $this->tile('cash_received', $cash['totals']['inflows']),
                $this->tile('costs_incurred', $pnl['totals']['expenses']),
                $this->tile('cash_paid', $cash['totals']['outflows']),
            ],
            'net_profit' => $pnl['totals']['net_profit'],
            'cash_balance' => ['total' => $cash['totals']['closing'], 'banks' => array_map(fn (array $r): array => ['bank' => $r['bank'], 'balance' => $r['closing']], $cash['rows'])],
            'outstanding_invoices' => ['total' => $ar['totals']['total'], 'count' => count($ar['rows']), 'overdue' => $this->sum(array_filter($ar['rows'], fn (array $r): bool => $r['days_overdue'] > 0), 'outstanding'), 'top' => array_slice($ar['rows'], 0, 5)],
            'claims' => ['pending_approval' => $claims['totals']['pending_approval'], 'approved_unpaid' => $claims['totals']['approved_unpaid']],
            'top_projects' => array_map(function (array $r): array {
                $p = $this->projects->snapshot($r['project_id']);

                return $r + ['label' => $p !== null ? "{$p['code']} {$p['name']}" : 'Project #'.$r['project_id']];
            }, $this->finance->projectContribution($from, $to)),
            'metrics' => $this->definitions(['revenue_invoiced', 'cash_received', 'costs_incurred', 'cash_paid', 'cash_balance', 'outstanding_invoices', 'claims_pending', 'claims_approved_unpaid', 'top_projects']),
        ];
    }

    /** @return array<string, mixed> */
    public function finance(User $user, Carbon $from, Carbon $to): array
    {
        $this->authorizeScope($user, 'dashboard_finance');
        $months = [];
        for ($d = $from->copy()->startOfMonth(); $d->lte($to); $d->addMonth()) {
            $end = $d->copy()->endOfMonth()->min($to);
            $p = $this->finance->profitAndLoss($d->copy()->max($from), $end, 'accrual');
            $months[] = ['month' => $d->format('Y-m'), 'income' => $p['totals']['income'], 'expenses' => $p['totals']['expenses'], 'net_profit' => $p['totals']['net_profit']];
        }
        $ap = $this->finance->payablesAgeing($to);
        $exp = $this->finance->expensesByCategory($from, $to);
        $recon = $this->finance->reconciliationSummary($to);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'monthly_pnl' => $months,
            'expense_by_category' => ['rows' => $exp['rows'], 'total' => $exp['totals']['total']],
            'unpaid_bills' => ['total' => $ap['totals']['total'], 'buckets' => array_diff_key($ap['totals'], ['total' => true]), 'count' => count($ap['rows'])],
            'collections' => $this->commercial->collectionsByMonth($from, $to),
            'reconciliation' => ['rows' => $recon['rows'], 'unmatched' => (int) $recon['totals']['unmatched_lines']],
            'metrics' => $this->definitions(['monthly_pnl', 'expense_by_category', 'unpaid_bills', 'collections', 'reconciliation_status']),
        ];
    }

    private function authorizeScope(User $user, string $resource): void
    {
        if (! $this->permissions->can($user, PermissionAction::View, $resource)) {
            throw new AuthorizationException('You do not have access to this dashboard.');
        }
    }

    /** @return array{metric_id: string, name: string, value: string, definition: string} */
    private function tile(string $id, string $value): array
    {
        return ['metric_id' => $id, 'name' => self::DEFINITIONS[$id]['name'], 'value' => $value, 'definition' => self::DEFINITIONS[$id]['definition']];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{name: string, definition: string, source: string, rule: string}>
     */
    private function definitions(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $d = self::DEFINITIONS[$id];
            $out[$id] = ['name' => $d['name'], 'definition' => $d['definition'], 'source' => $d['source'], 'rule' => $d['rule']];
        }

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function sum(array $rows, string $key): string
    {
        $t = Money::zero();
        foreach ($rows as $r) {
            $t = $t->plus(Money::myr((string) $r[$key]));
        }

        return (string) $t->getAmount();
    }
}
