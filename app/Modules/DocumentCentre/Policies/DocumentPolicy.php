<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Policies;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Services\DocumentAccess;

/**
 * Every document route, download, search and report goes through this policy (deny-by-default).
 *
 * @see SRS M06-02, M06-04, M06-05, NFR-02, UAT-10
 */
final class DocumentPolicy extends ResourcePolicy
{
    protected string $model = Document::class;

    public function __construct(PermissionService $permissions, private readonly DocumentAccess $access)
    {
        parent::__construct($permissions);
    }

    public function view(User $user, PermissionResource $resource): bool
    {
        return $resource instanceof Document && $this->access->canView($user, $resource);
    }

    public function download(User $user, Document $document): bool
    {
        return $this->access->canView($user, $document);
    }

    public function update(User $user, PermissionResource $resource): bool
    {
        return $resource instanceof Document && $this->access->canEdit($user, $resource);
    }

    /** Hard delete: documents.delete (OWN) on a document the user can see. */
    public function delete(User $user, PermissionResource $resource): bool
    {
        return $resource instanceof Document && $this->access->canView($user, $resource)
            && $this->permissions->can($user, PermissionAction::Delete, $resource);
    }

    /** Legal hold / retention changes: documents.approve (OWN). */
    public function approve(User $user, PermissionResource $resource): bool
    {
        return $resource instanceof Document && $this->access->canView($user, $resource)
            && $this->permissions->can($user, PermissionAction::Approve, $resource);
    }
}
