<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\HumanResources\Models\StaffBankDetail;

/** Deny-by-default matrix check for StaffBankDetail. @see SRS M04-01 … M04-06, NFR-02 */
final class StaffBankDetailPolicy extends ResourcePolicy
{
    protected string $model = StaffBankDetail::class;
}
