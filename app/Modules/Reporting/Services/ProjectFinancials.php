<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Commercial\Reports\CommercialReports;
use App\Modules\Finance\Reports\FinanceReports;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Project financials and the document chain (read model over M01/M02/M03/M06):
 *   contribution margin = net revenue (income lines tagged to the project: invoices − credit notes, other income)
 *                         − linked direct costs (expense lines tagged to the project: expenses, claims, bills,
 *                           part-time pay; trips from M07 later). Overhead (untagged) is excluded (§8.1).
 *   chain = Lead → Quotation → Client PO/LOA → Contract → Project → Invoices → Receipts (M02-02).
 *
 * @see SRS M02-02, M02-05, M11-01, §8.1
 */
final class ProjectFinancials
{
    public const SOURCES = ['transaction' => 'Direct expenses', 'claim' => 'Staff claims', 'bill' => 'Vendor bills', 'payment_batch' => 'Part-time & contractor fees', 'invoice' => 'Invoices', 'credit_note' => 'Credit notes', 'reversal' => 'Reversals', 'manual' => 'Adjusting journals'];

    public function __construct(
        private readonly FinanceReports $finance,
        private readonly CommercialReports $commercial,
        private readonly ProjectDirectory $projects,
        private readonly ClientDirectory $clients,
        private readonly DocumentStore $documents,
    ) {}

    /** @return array<string, mixed> */
    public function for(int $projectId, User $viewer): array
    {
        $visible = $this->projects->visibleProjectIds($viewer);
        if ($visible !== '*' && ! in_array($projectId, $visible, true)) {
            throw new AuthorizationException('You are not assigned to this project.');
        }
        $p = $this->projects->snapshot($projectId) ?? throw new AuthorizationException('Unknown project.');
        $lines = $this->finance->projectLines($projectId);
        $revenue = Money::zero();
        $costs = Money::zero();
        $bySource = [];
        foreach ($lines as $l) {
            $amount = Money::myr($l['amount']);
            $key = $l['type'].':'.$l['source_type'];
            $bySource[$key] = ['type' => $l['type'], 'source' => self::SOURCES[$l['source_type']] ?? ucfirst(str_replace('_', ' ', $l['source_type'])), 'amount' => Money::myr($bySource[$key]['amount'] ?? '0')->plus($amount)->getAmount()->__toString()];
            $l['type'] === 'income' ? $revenue = $revenue->plus($amount) : $costs = $costs->plus($amount);
        }
        $contribution = $revenue->minus($costs);
        $margin = $revenue->isPositive() ? (string) $contribution->getAmount()->multipliedBy(100)->dividedBy($revenue->getAmount(), 1, RoundingMode::HALF_UP) : null;
        $docs = $this->commercial->projectDocuments($projectId, $p['source_quotation_id']);
        $contracts = array_values(array_filter($this->documents->linkedTo('project', $projectId), fn (array $d): bool => in_array($d['link_type'], ['contract', 'agreement', 'client_po'], true)));

        return [
            'project' => $p + ['client' => $this->clients->snapshot($p['client_id'])['name'] ?? null],
            'totals' => ['net_revenue' => (string) $revenue->getAmount(), 'direct_costs' => (string) $costs->getAmount(), 'contribution' => (string) $contribution->getAmount(), 'margin_percent' => $margin,
                'contract_value' => $p['contract_value'], 'invoiced' => $this->sum($docs['invoices'], 'total'), 'received' => $this->sum($docs['receipts'], 'amount', fn ($r) => $r['status'] !== 'void')],
            'by_source' => array_values($bySource),
            'lines' => $lines,
            'chain' => [
                'lead' => $p['lead_id'] !== null ? $this->projects->leadSnapshot($p['lead_id']) : null,
                'quotations' => $docs['quotations'], 'client_pos' => $docs['client_pos'], 'contracts' => $contracts,
                'invoices' => $docs['invoices'], 'credit_notes' => $docs['credit_notes'], 'receipts' => $docs['receipts'],
            ],
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function sum(array $rows, string $key, ?\Closure $filter = null): string
    {
        $t = BigDecimal::zero();
        foreach ($rows as $r) {
            if (($r['status'] ?? '') === 'void' || ($filter !== null && ! $filter($r))) {
                continue;
            }
            $t = $t->plus((string) $r[$key]);
        }

        return (string) $t->toScale(2);
    }
}
