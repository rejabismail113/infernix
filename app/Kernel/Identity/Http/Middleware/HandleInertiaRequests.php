<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Middleware;

use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return list<array{label: string, href: string, group: string, icon: string}> */
    private function nav(User $user): array
    {
        $p = app(PermissionService::class);
        $items = [
            ['Finance overview', 'finance.overview', 'claims', 'view', 'Finance', 'chart'],
            ['Claims', 'finance.claims.index', 'claims', 'view', 'Finance', 'audit'],
            ['Expenses & income', 'finance.transactions.index', 'transactions', 'view', 'Finance', 'swap'],
            ['Bills', 'finance.bills.index', 'bills', 'view', 'Finance', 'document'],
            ['Payments', 'finance.payments.index', 'payments', 'view', 'Finance', 'upload'],
            ['Bank', 'finance.bank.index', 'bank', 'view', 'Finance', 'building'],
            ['Journals', 'finance.journals.index', 'journals', 'view', 'Finance', 'list'],
            ['Chart of accounts', 'finance.accounts.index', 'chart_accounts', 'view', 'Finance', 'hash'],
            ['Period close', 'finance.periods.index', 'fiscal_periods', 'view', 'Finance', 'lock'],
            ['Finance reports', 'finance.reports.index', 'finance_reports', 'view', 'Finance', 'report'],
            ['Vendors', 'procurement.vendors.index', 'vendors', 'view', 'Procurement', 'users'],
            ['Documents', 'documents.index', 'documents', 'view', 'Documents', 'document'],
            ['Document reports', 'documents.reports.inventory', 'documents', 'view', 'Documents', 'report'],
            ['Users', 'admin.users.index', 'users', 'view', 'Administration', 'users'],
            ['Permissions', 'admin.permissions.index', 'permissions', 'view', 'Administration', 'shield'],
            ['Delegations', 'admin.delegations.index', 'delegations', 'view', 'Administration', 'swap'],
            ['Company', 'admin.company.edit', 'company', 'view', 'Administration', 'building'],
            ['Fiscal periods', 'admin.fiscal-periods.index', 'fiscal_periods', 'view', 'Administration', 'calendar'],
            ['Numbering', 'admin.numbering.index', 'numbering', 'view', 'Administration', 'hash'],
            ['Dictionaries', 'admin.dictionaries.index', 'dictionaries', 'view', 'Administration', 'list'],
            ['Notification rules', 'admin.notification-rules.index', 'notification_rules', 'view', 'Administration', 'bell'],
            ['Audit log', 'admin.audit-log.index', 'audit_log', 'view', 'Administration', 'audit'],
            ['Access reports', 'admin.reports.user-access', 'reports_access', 'view', 'Administration', 'report'],
        ];
        $nav = [['label' => 'Dashboard', 'href' => route('dashboard'), 'group' => 'Overview', 'icon' => 'home']];
        foreach ($items as [$label, $routeName, $resource, $action, $group, $icon]) {
            if ($p->can($user, $action, $resource)) {
                $nav[] = ['label' => $label, 'href' => route($routeName), 'group' => $group, 'icon' => $icon];
            }
        }

        return $nav;
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email] : null,
            ],
            'app' => [
                'name' => config('app.name'),
                'company' => config('infernix.company'),
                'currency' => config('infernix.currency'),
                'timezone' => config('infernix.timezone'),
            ],
            // Sidebar entries are filtered by the same PermissionService the routes use (UI hiding is NOT a control).
            'nav' => fn () => $user instanceof User ? $this->nav($user) : [],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'import' => $request->session()->get('import'),
            ],
        ];
    }
}
