<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Finance\Models\Bill;

/** @see SRS M01, NFR-02 */
final class BillPolicy extends ResourcePolicy
{
    protected string $model = Bill::class;
}
