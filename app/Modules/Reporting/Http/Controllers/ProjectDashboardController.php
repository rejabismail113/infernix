<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Projects\Reports\ProjectsReports;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project dashboard (M11-03): contract value, delivery / payment status, contribution margin, due and overdue
 * milestones and the training pipeline — for the projects the user may see (PM = assigned only).
 *
 * @see SRS M11-03, M02-05, M02-07
 */
final class ProjectDashboardController
{
    public function __invoke(Request $request, PermissionService $permissions, ProjectDirectory $projects, ProjectsReports $reports): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::View, 'projects'), 403);
        $visible = $projects->visibleProjectIds($user);
        $rows = DB::table('reporting.project_margin')->when($visible !== '*', fn ($q) => $q->whereIn('project_id', $visible === [] ? [0] : $visible))
            ->whereNotIn('status_delivery', ['closed'])->orderBy('project_code')->get();
        $milestones = DB::table('milestones')->whereIn('project_id', $rows->pluck('project_id'))->where('status', '!=', 'done')->whereNotNull('due_on')
            ->where('due_on', '<=', today()->addDays(30)->toDateString())->orderBy('due_on')->get(['project_id', 'title', 'due_on', 'status']);
        $totals = ['contract_value' => BigDecimal::zero(), 'net_revenue' => BigDecimal::zero(), 'direct_costs' => BigDecimal::zero(), 'contribution' => BigDecimal::zero()];
        $projectRows = $rows->map(function ($r) use (&$totals, $milestones): array {
            foreach (array_keys($totals) as $k) {
                $totals[$k] = $totals[$k]->plus((string) $r->{$k});
            }
            $rev = BigDecimal::of((string) $r->net_revenue);
            $mine = $milestones->where('project_id', $r->project_id);

            return ['id' => (int) $r->project_id, 'code' => $r->project_code, 'name' => $r->project_name, 'client' => $r->client_name, 'engagement_type' => $r->engagement_type,
                'status_delivery' => $r->status_delivery, 'status_payment' => $r->status_payment, 'contract_value' => (string) BigDecimal::of((string) $r->contract_value)->toScale(2),
                'net_revenue' => (string) $rev->toScale(2), 'direct_costs' => (string) BigDecimal::of((string) $r->direct_costs)->toScale(2), 'contribution' => (string) BigDecimal::of((string) $r->contribution)->toScale(2),
                'margin_percent' => $rev->isPositive() ? (string) BigDecimal::of((string) $r->contribution)->multipliedBy(100)->dividedBy($rev, 1, RoundingMode::HALF_UP) : null,
                'overdue' => $mine->filter(fn ($m) => $m->due_on < today()->toDateString())->count(), 'next_milestone' => ($n = $mine->first(fn ($m) => $m->due_on >= today()->toDateString())) ? ['title' => $n->title, 'due_on' => $n->due_on] : null];
        })->values();
        $training = $reports->trainingRegister($user, today(), today()->addDays(90));

        return Inertia::render('Reporting/ProjectDashboard', [
            'projects' => $projectRows,
            'totals' => array_map(fn (BigDecimal $v): string => (string) $v->toScale(2), $totals),
            'milestones' => $milestones->map(fn ($m): array => ['project_id' => (int) $m->project_id, 'title' => $m->title, 'due_on' => $m->due_on, 'status' => $m->status, 'overdue' => $m->due_on < today()->toDateString()])->values(),
            'training' => $training['rows'],
        ]);
    }
}
