<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\FiscalPeriod;

/** @see SRS M01-08, M12-05, UAT-09 */
final class FiscalPeriodPolicy extends ResourcePolicy
{
    protected string $model = FiscalPeriod::class;
}
