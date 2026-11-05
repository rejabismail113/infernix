<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Procurement\Models\Vendor;

/** @see SRS M10-01 */
final class VendorPolicy extends ResourcePolicy
{
    protected string $model = Vendor::class;
}
