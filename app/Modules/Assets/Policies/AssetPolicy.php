<?php

declare(strict_types=1);

namespace App\Modules\Assets\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Assets\Models\Asset;

/** Deny-by-default matrix check for Asset. @see SRS M05-01 … M05-05, NFR-02 */
final class AssetPolicy extends ResourcePolicy
{
    protected string $model = Asset::class;
}
