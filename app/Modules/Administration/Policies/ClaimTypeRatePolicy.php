<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\ClaimTypeRate;

/** @see SRS M01-04, §2.2 */
final class ClaimTypeRatePolicy extends ResourcePolicy
{
    protected string $model = ClaimTypeRate::class;
}
