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
