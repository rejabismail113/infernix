<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Policies;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;

/** Matrix editing is type-level only (resource "permissions"). @see SRS M12-02 */
final class PermissionPolicy
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, PermissionAction::View, 'permissions');
    }

    public function update(User $user): bool
    {
        return $this->permissions->can($user, PermissionAction::Edit, 'permissions');
    }
}
