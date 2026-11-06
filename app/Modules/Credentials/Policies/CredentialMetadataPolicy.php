<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Policies;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Credentials\Models\CredentialMetadata;

/**
 * Credential metadata policy. Reveal needs BOTH the matrix `reveal` right AND a separate vault entitlement
 * (active grant) — or being the resource owner — checked independently (M13-03).
 *
 * @see SRS M13-03, M13-06, NFR-02
 */
final class CredentialMetadataPolicy extends ResourcePolicy
{
    protected string $model = CredentialMetadata::class;

    public function revealSecret(User $user, CredentialMetadata $c): bool
    {
        return $c->status === 'active'
            && $this->permissions->can($user, PermissionAction::Reveal, $c)
            && ($c->owner_id === $user->id || $c->hasActiveEntitlement($user));
    }

    /** Decide access requests: approve:own = the credential's owner; approve:all = Owner role (WF-07). */
    public function decideAccess(User $user, CredentialMetadata $c): bool
    {
        return $this->permissions->can($user, PermissionAction::Approve, $c);
    }

    public function requestAccess(User $user, CredentialMetadata $c): bool
    {
        return $this->permissions->can($user, PermissionAction::Create, 'credential_requests') && $c->status === 'active';
    }
}
