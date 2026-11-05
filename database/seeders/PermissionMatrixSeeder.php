<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Kernel\Identity\Models\Permission;
use App\Kernel\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Seeds the kernel/M12 slice of docs/PERMISSION_MATRIX.md. Later modules append their resources.
 * Format: resource => [ROLE => 'action:scope action:scope …']. Anything absent = none (deny-by-default).
 *
 * @see SRS G-02, M12-02, §2, docs/PERMISSION_MATRIX.md
 */
final class PermissionMatrixSeeder extends Seeder
{
    /** @return array<string, array<string, string>> */
    public static function matrix(): array
    {
        return [
            'users' => [
                'OWN' => 'view:all create:all edit:all delete:all export:all',
                'FIN' => 'view:all',
                'HR' => 'view:all create:all edit:all',
                'STF' => 'view:own edit:own',
                'PM' => 'view:own edit:own',
                'APP' => 'view:own',
                'AUD' => 'view:all',
            ],
            'permissions' => ['OWN' => 'view:all edit:all', 'FIN' => 'view:all', 'AUD' => 'view:all'],
            'delegations' => ['OWN' => 'view:all create:all edit:all', 'FIN' => 'view:all create:own edit:own', 'PM' => 'view:own create:own edit:own', 'HR' => 'view:own create:own edit:own', 'APP' => 'view:assigned', 'AUD' => 'view:all'],
            'company' => ['OWN' => 'view:all edit:all', 'FIN' => 'view:all edit:all', 'HR' => 'view:all', 'PM' => 'view:all', 'AUD' => 'view:all'],
            'fiscal_periods' => ['OWN' => 'view:all create:all edit:all approve:all', 'FIN' => 'view:all create:all edit:all approve:all', 'AUD' => 'view:all'],
            'numbering' => ['OWN' => 'view:all edit:all', 'FIN' => 'view:all', 'AUD' => 'view:all'],
            'dictionaries' => ['OWN' => 'view:all create:all edit:all', 'FIN' => 'view:all create:all edit:all', 'HR' => 'view:all edit:all', 'PM' => 'view:all', 'STF' => 'view:all', 'APP' => 'view:all', 'AUD' => 'view:all'],
            'notification_rules' => ['OWN' => 'view:all create:all edit:all', 'FIN' => 'view:all create:all edit:all', 'HR' => 'view:all edit:all'],
            'audit_log' => ['OWN' => 'view:all export:all', 'FIN' => 'view:all', 'AUD' => 'view:all export:all'],
            'backup_status' => ['OWN' => 'view:all approve:all', 'AUD' => 'view:all'],
            'reports_access' => ['OWN' => 'view:all export:all', 'HR' => 'view:all', 'AUD' => 'view:all export:all'],
            // M01 Finance (Prompt 2)
            'chart_accounts' => ['OWN' => 'view:all create:all edit:all', 'FIN' => 'view:all create:all edit:all', 'AUD' => 'view:all export:all'],
            'journals' => ['OWN' => 'view:all create:all approve:all export:all', 'FIN' => 'view:all create:all approve:all export:all', 'AUD' => 'view:all export:all', 'APP' => 'approve:assigned'],
            'transactions' => ['OWN' => 'view:all create:all edit:all approve:all export:all', 'FIN' => 'view:all create:all edit:all approve:all export:all', 'AUD' => 'view:all export:all'],
            'tax_review' => ['OWN' => 'edit:all', 'FIN' => 'edit:all', 'AUD' => 'edit:all'],
            'claims' => [
                'OWN' => 'view:all create:all edit:all approve:all pay:all export:all reveal:all',
                'FIN' => 'view:all create:all edit:all approve:all pay:all export:all',
                'HR' => 'view:all reveal:all',
                'PM' => 'view:own create:own edit:own',
                'STF' => 'view:own create:own edit:own',
                'AUD' => 'view:all export:all',
                'APP' => 'view:assigned approve:assigned',
            ],
            'bills' => ['OWN' => 'view:all create:all edit:all approve:all pay:all export:all', 'FIN' => 'view:all create:all edit:all approve:all pay:all export:all', 'AUD' => 'view:all export:all', 'APP' => 'approve:assigned'],
            'payments' => ['OWN' => 'view:all create:all edit:all approve:all pay:all export:all', 'FIN' => 'view:all create:all edit:all approve:all pay:all export:all', 'AUD' => 'view:all export:all', 'APP' => 'approve:assigned'],
            'bank' => ['OWN' => 'view:all create:all edit:all', 'FIN' => 'view:all create:all edit:all', 'AUD' => 'view:all export:all'],
            'finance_reports' => ['OWN' => 'view:all export:all', 'FIN' => 'view:all export:all', 'AUD' => 'view:all export:all'],
            'vendors' => ['OWN' => 'view:all create:all edit:all', 'FIN' => 'view:all create:all edit:all', 'PM' => 'view:all', 'AUD' => 'view:all'],
            // M02 / M03 Commercial (Prompt 3)
            'clients' => ['OWN' => 'view:all create:all edit:all export:all', 'FIN' => 'view:all create:all edit:all export:all', 'PM' => 'view:all create:all edit:all', 'AUD' => 'view:all export:all'],
            'projects' => ['OWN' => 'view:all create:all edit:all export:all', 'FIN' => 'view:all create:all edit:all export:all', 'PM' => 'view:assigned create:all edit:assigned', 'AUD' => 'view:all export:all'],
            'quotations' => ['OWN' => 'view:all create:all edit:all approve:all export:all', 'FIN' => 'view:all create:all edit:all approve:all export:all', 'PM' => 'view:own create:own edit:own', 'AUD' => 'view:all export:all', 'APP' => 'approve:assigned'],
            'invoices' => ['OWN' => 'view:all create:all edit:all approve:all export:all', 'FIN' => 'view:all create:all edit:all approve:all export:all', 'AUD' => 'view:all export:all', 'APP' => 'approve:assigned'],
            'credit_notes' => ['OWN' => 'view:all create:all edit:all approve:all export:all', 'FIN' => 'view:all create:all edit:all approve:all export:all', 'AUD' => 'view:all export:all', 'APP' => 'approve:assigned'],
            'receipts' => ['OWN' => 'view:all create:all edit:all export:all', 'FIN' => 'view:all create:all edit:all export:all', 'AUD' => 'view:all export:all'],
            'document_templates' => ['OWN' => 'view:all edit:all', 'FIN' => 'view:all', 'AUD' => 'view:all'],
            // M11 Reporting (Prompt 3)
            'dashboard_owner' => ['OWN' => 'view:all', 'AUD' => 'view:all'],
            'dashboard_finance' => ['OWN' => 'view:all', 'FIN' => 'view:all', 'AUD' => 'view:all'],
            'exports' => ['OWN' => 'view:own create:all', 'FIN' => 'view:own create:all', 'HR' => 'view:own create:all', 'PM' => 'view:own create:all', 'AUD' => 'view:own create:all'],
            // M06: "assigned" = owner, explicit share, or may view a linked record (inheritance, SRS §6.3).
            'documents' => [
                'OWN' => 'view:all create:all edit:all delete:all export:all approve:all',
                'FIN' => 'view:assigned create:all edit:assigned export:assigned',
                'PM' => 'view:assigned create:all edit:assigned',
                'HR' => 'view:assigned create:all edit:assigned export:assigned',
                'STF' => 'view:assigned create:own edit:own',
                'AUD' => 'view:assigned export:assigned',
                'APP' => 'view:assigned',
            ],
        ];
    }

    public function run(): void
    {
        $roles = Role::query()->get()->keyBy('code');
        foreach (self::matrix() as $resource => $byRole) {
            foreach ($byRole as $roleCode => $spec) {
                /** @var Role $role */
                $role = $roles[$roleCode];
                foreach (explode(' ', $spec) as $pair) {
                    [$action, $scope] = explode(':', $pair);
                    Permission::withoutEvents(fn () => Permission::query()->firstOrCreate(
                        ['role_id' => $role->id, 'resource' => $resource, 'action' => $action, 'policy_version' => 1],
                        ['record_scope' => $scope, 'effective_from' => now()],
                    ));
                }
            }
        }
    }
}
