<?php

declare(strict_types=1);

namespace App\Modules\Assets\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Assets\Models\AssetDisposal;

/** Deny-by-default matrix check for AssetDisposal. @see SRS M05-01 … M05-05, NFR-02 */
final class AssetDisposalPolicy extends ResourcePolicy
{
    protected string $model = AssetDisposal::class;
}
