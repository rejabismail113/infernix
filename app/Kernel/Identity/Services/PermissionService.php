<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Services;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Support\Collection;

/**
 * Evaluates role + record scope. Deny-by-default: no matching effective permission → false.
 * The user's best (widest) scope across all their roles wins for a resource/action.
 *
 * @see SRS G-02, M12-02, §2, UAT-01, NFR-02
 */
final class PermissionService
{
    /** @var array<int, Collection<int, Permission>> */
    private array $cache = [];

    /**
     * @param  PermissionAction|string  $action  view|create|edit|approve|pay|export|delete|reveal
     * @param  PermissionResource|string  $resource  model instance (record-level) or resource name (type-level)
     */
    public function can(User $user, PermissionAction|string $action, PermissionResource|string $resource): bool
    {
        $action = $action instanceof PermissionAction ? $action : PermissionAction::tryFrom($action);
        if ($action === null) {
            return false;
        }
        if (! $this->userMayAct($user)) {
            return false;
        }

        $resourceName = $resource instanceof PermissionResource ? $resource::resourceName() : $resource;
        $scope = $this->scopeFor($user, $resourceName, $action);

        if ($scope === RecordScope::None) {
            return false;
        }
        if (! $resource instanceof PermissionResource) {
            // Type-level check (e.g. "may this user open the list page at all").
            return true;
        }

        return match ($scope) {
            RecordScope::All => true,
            RecordScope::Assigned => $resource->isAssignedTo($user) || $resource->isOwnedBy($user),
            RecordScope::Own => $resource->isOwnedBy($user),
        };
    }

    /** Widest effective scope the user holds for resource/action across roles. */
    public function scopeFor(User $user, string $resource, PermissionAction $action): RecordScope
    {
        $best = RecordScope::None;
        foreach ($this->permissionsFor($user) as $permission) {
            if ($permission->resource !== $resource || $permission->action !== $action) {
                continue;
            }
            if ($permission->record_scope->rank() > $best->rank()) {
                $best = $permission->record_scope;
            }
        }
        // Read-only roles can never hold write actions, regardless of matrix rows (AUD guard).
        if ($best !== RecordScope::None && ! $action->isReadOnly() && $this->onlyReadOnlyRoles($user)) {
            return RecordScope::None;
        }

        return $best;
    }

    /** Global guards: active account, AUD time window. */
    public function userMayAct(User $user): bool
    {
        return $user->isActive() && $user->withinAccessWindow();
    }

    /** @return Collection<int, Permission> */
    public function permissionsFor(User $user): Collection
    {
        if (! isset($this->cache[$user->id])) {
            $roleIds = $user->roles->pluck('id')->all();
            $this->cache[$user->id] = $roleIds === []
                ? new Collection
                : Permission::query()->whereIn('role_id', $roleIds)->effective()->get();
        }

        return $this->cache[$user->id];
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$user->id]);
        }
    }

    private function onlyReadOnlyRoles(User $user): bool
    {
        return $user->roles->isNotEmpty() && $user->roles->every(fn ($r): bool => $r->read_only);
    }
}
