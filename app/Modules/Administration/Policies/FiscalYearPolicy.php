<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\FiscalYear;

/** @see SRS G-03, M01-08, M12-05 */
final class FiscalYearPolicy extends ResourcePolicy
{
    protected string $model = FiscalYear::class;
}
