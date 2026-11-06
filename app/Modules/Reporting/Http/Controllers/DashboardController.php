<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Reporting\Services\MetricService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner Executive and Finance dashboards. Numbers come from server-side queries with the same policy filters
 * as the report pages; the routes are deny-by-default (dashboard_owner / dashboard_finance : view).
 *
 * @see SRS M11-01, M11-02, M11-04, M11-05
 */
final class DashboardController
{
    public function __construct(private readonly MetricService $metrics, private readonly PermissionService $permissions) {}

    public function owner(Request $request): Response
    {
        $user = $this->authorized($request, 'dashboard_owner');
        [$from, $to] = $this->period($request);

        return Inertia::render('Reporting/OwnerDashboard', ['data' => $this->metrics->owner($user, $from, $to), 'links' => $this->links($user)]);
    }

    public function finance(Request $request): Response
    {
        $user = $this->authorized($request, 'dashboard_finance');
        [$from, $to] = $this->period($request);

        return Inertia::render('Reporting/FinanceDashboard', ['data' => $this->metrics->finance($user, $from, $to), 'links' => $this->links($user)]);
    }

    private function authorized(Request $request, string $resource): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->permissions->can($user, PermissionAction::View, $resource), 403);

        return $user;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = Carbon::parse($f['to'] ?? today()->toDateString());
        $from = Carbon::parse($f['from'] ?? $to->copy()->startOfYear()->toDateString());
        if ($from->diffInDays($to) > 731) {
            $from = $to->copy()->subYears(2)->addDay();
        }

        return [$from, $to];
    }

    /** @return array<string, bool> */
    private function links(User $user): array
    {
        return [
            'owner' => $this->permissions->can($user, PermissionAction::View, 'dashboard_owner'),
            'finance' => $this->permissions->can($user, PermissionAction::View, 'dashboard_finance'),
            'reports' => $this->permissions->can($user, PermissionAction::View, 'finance_reports'),
            'invoices' => $this->permissions->can($user, PermissionAction::View, 'invoices'),
            'claims' => $this->permissions->can($user, PermissionAction::View, 'claims'),
            'bank' => $this->permissions->can($user, PermissionAction::View, 'bank'),
            'bills' => $this->permissions->can($user, PermissionAction::View, 'bills'),
        ];
    }
}
