<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\HumanResources\Models\Timesheet;

/** Deny-by-default matrix check for Timesheet. @see SRS M04-01 … M04-06, NFR-02 */
final class TimesheetPolicy extends ResourcePolicy
{
    protected string $model = Timesheet::class;
}
