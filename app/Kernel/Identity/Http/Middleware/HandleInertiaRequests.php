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

    /** @return list<array{label: string, href: string, group: string}> */
    private function nav(User $user): array
    {
        $p = app(PermissionService::class);
        $items = [
            ['Documents', 'documents.index', 'documents', 'view', 'Documents'],
            ['Document reports', 'documents.reports.inventory', 'documents', 'view', 'Documents'],
            ['Users', 'admin.users.index', 'users', 'view', 'Administration'],
            ['Permissions', 'admin.permissions.index', 'permissions', 'view', 'Administration'],
            ['Delegations', 'admin.delegations.index', 'delegations', 'view', 'Administration'],
            ['Company', 'admin.company.edit', 'company', 'view', 'Administration'],
            ['Fiscal periods', 'admin.fiscal-periods.index', 'fiscal_periods', 'view', 'Administration'],
            ['Numbering', 'admin.numbering.index', 'numbering', 'view', 'Administration'],
            ['Dictionaries', 'admin.dictionaries.index', 'dictionaries', 'view', 'Administration'],
            ['Notification rules', 'admin.notification-rules.index', 'notification_rules', 'view', 'Administration'],
            ['Audit log', 'admin.audit-log.index', 'audit_log', 'view', 'Administration'],
            ['Access reports', 'admin.reports.user-access', 'reports_access', 'view', 'Administration'],
        ];
        $nav = [];
        foreach ($items as [$label, $routeName, $resource, $action, $group]) {
            if ($p->can($user, $action, $resource)) {
                $nav[] = ['label' => $label, 'href' => route($routeName), 'group' => $group];
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
            ],
        ];
    }
}
