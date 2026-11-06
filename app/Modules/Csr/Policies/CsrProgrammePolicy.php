<?php

declare(strict_types=1);

namespace App\Modules\Csr\Policies;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Csr\Models\CsrProgramme;

/** Deny-by-default matrix check for CSR programmes; evidence photos need csr_evidence rights. @see SRS M08-01 … M08-04, NFR-02 */
final class CsrProgrammePolicy extends ResourcePolicy
{
    protected string $model = CsrProgramme::class;

    public function viewEvidence(User $user, CsrProgramme $programme): bool
    {
        return $this->view($user, $programme) && $this->permissions->can($user, PermissionAction::View, 'csr_evidence');
    }

    public function manageEvidence(User $user, CsrProgramme $programme): bool
    {
        return $this->view($user, $programme) && $this->permissions->can($user, PermissionAction::Edit, 'csr_evidence');
    }
}
