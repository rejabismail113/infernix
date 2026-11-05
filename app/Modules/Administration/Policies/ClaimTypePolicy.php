<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\ClaimType;

/** @see SRS M01-03, M01-04, §2.2 */
final class ClaimTypePolicy extends ResourcePolicy
{
    protected string $model = ClaimType::class;
}
