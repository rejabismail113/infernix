<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Policies;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;

/**
 * Generic policy for any PermissionResource model: every ability maps onto the permission matrix.
 * Module policies extend this and override only what needs extra rules (e.g. maker-checker).
 *
 * @see SRS G-02, M12-02, NFR-02
 */
abstract class ResourcePolicy
{
    /** @var class-string<PermissionResource> */
    protected string $model;

    public function __construct(protected readonly PermissionService $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, PermissionAction::View, $this->model::resourceName());
    }

    public function view(User $user, PermissionResource $resource): bool
    {
        return $this->permissions->can($user, PermissionAction::View, $resource);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can($user, PermissionAction::Create, $this->model::resourceName());
    }

    public function update(User $user, PermissionResource $resource): bool
    {
        return $this->permissions->can($user, PermissionAction::Edit, $resource);
    }

    public function delete(User $user, PermissionResource $resource): bool
    {
        return $this->permissions->can($user, PermissionAction::Delete, $resource);
    }

    public function approve(User $user, PermissionResource $resource): bool
    {
        return $this->permissions->can($user, PermissionAction::Approve, $resource);
    }

    public function pay(User $user, PermissionResource $resource): bool
    {
        return $this->permissions->can($user, PermissionAction::Pay, $resource);
    }

    public function export(User $user): bool
    {
        return $this->permissions->can($user, PermissionAction::Export, $this->model::resourceName());
    }

    public function reveal(User $user, PermissionResource $resource): bool
    {
        return $this->permissions->can($user, PermissionAction::Reveal, $resource);
    }
}
