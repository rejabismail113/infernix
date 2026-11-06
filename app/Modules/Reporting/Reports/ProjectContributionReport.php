<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Finance\Reports\FinanceReports;
use Illuminate\Support\Carbon;

/** Revenue & contribution by project (M02 output; §8.1: overhead excluded), limited to projects the user may see. */
final class ProjectContributionReport
{
    public function __construct(private readonly FinanceReports $finance, private readonly ProjectDirectory $projects) {}

    /** @return array<string, mixed> */
    public function run(Carbon $from, Carbon $to, ?User $user): array
    {
        $visible = $user === null ? '*' : $this->projects->visibleProjectIds($user);
        $rows = [];
        $rev = Money::zero();
        $cost = Money::zero();
        foreach ($this->finance->projectContribution($from, $to, 10000) as $r) {
            if ($visible !== '*' && ! in_array($r['project_id'], $visible, true)) {
                continue;
            }
            $p = $this->projects->snapshot($r['project_id']);
            $rows[] = ['project_id' => $r['project_id'], 'project' => $p !== null ? "{$p['code']} {$p['name']}" : '#'.$r['project_id'], 'status' => $p['status_delivery'] ?? null, 'contract_value' => $p['contract_value'] ?? null,
                'revenue' => $r['revenue'], 'cost' => $r['cost'], 'contribution' => $r['contribution']];
            $rev = $rev->plus(Money::myr($r['revenue']));
            $cost = $cost->plus(Money::myr($r['cost']));
        }

        return ['title' => 'Revenue & contribution by project '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'project', 'label' => 'Project'], ['key' => 'status', 'label' => 'Delivery'], ['key' => 'contract_value', 'label' => 'Contract value', 'numeric' => true], ['key' => 'revenue', 'label' => 'Net revenue', 'numeric' => true], ['key' => 'cost', 'label' => 'Direct costs', 'numeric' => true], ['key' => 'contribution', 'label' => 'Contribution', 'numeric' => true]],
            'rows' => $rows, 'totals' => ['revenue' => (string) $rev->getAmount(), 'direct_costs' => (string) $cost->getAmount(), 'contribution' => (string) $rev->minus($cost)->getAmount()],
            'notes' => ['Contribution = net revenue − direct costs linked to the project (expenses, claims, bills, part-time pay). Overhead is excluded (§8.1).']];
    }
}
