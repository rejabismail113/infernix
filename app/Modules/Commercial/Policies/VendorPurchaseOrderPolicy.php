<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Commercial\Models\VendorPurchaseOrder;

/** Deny-by-default matrix check for VendorPurchaseOrder. @see SRS M03-05, M03-06, NFR-02 */
final class VendorPurchaseOrderPolicy extends ResourcePolicy
{
    protected string $model = VendorPurchaseOrder::class;
}
