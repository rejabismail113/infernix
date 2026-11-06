<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Procurement\Models\GoodsReceipt;

/** Deny-by-default matrix check for GoodsReceipt. @see SRS M10-01 … M10-05, NFR-02 */
final class GoodsReceiptPolicy extends ResourcePolicy
{
    protected string $model = GoodsReceipt::class;
}
