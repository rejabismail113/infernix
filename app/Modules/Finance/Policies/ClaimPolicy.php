<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Finance\Models\Claim;

/**
 * STF/PM: own claims only (UAT-01). Clinical detail of medical lines: `reveal` (HR/OWN) or the claimant.
 *
 * @see SRS M01-03, UAT-01, §2.1
 */
final class ClaimPolicy extends ResourcePolicy
{
    protected string $model = Claim::class;

    public function update(User $user, ?PermissionResource $resource = null): bool
    {
        if ($resource instanceof Claim && $resource->isEditableBy($user)) {
            return $this->permissions->can($user, PermissionAction::Edit, $resource);
        }

        return parent::update($user, $resource);
    }

    public function viewClinical(User $user, Claim $claim): bool
    {
        return $claim->isOwnedBy($user) && $this->permissions->userMayAct($user)
            || $this->permissions->can($user, PermissionAction::Reveal, $claim);
    }
}
