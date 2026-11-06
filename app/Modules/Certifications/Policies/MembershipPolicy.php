<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Certifications\Models\Membership;

/** Deny-by-default matrix check for Membership. @see SRS M09-01 … M09-04, NFR-02 */
final class MembershipPolicy extends ResourcePolicy
{
    protected string $model = Membership::class;
}
