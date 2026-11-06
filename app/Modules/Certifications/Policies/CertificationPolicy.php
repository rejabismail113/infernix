<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Certifications\Models\Certification;

/** Deny-by-default matrix check for Certification. @see SRS M09-01 … M09-04, NFR-02 */
final class CertificationPolicy extends ResourcePolicy
{
    protected string $model = Certification::class;
}
