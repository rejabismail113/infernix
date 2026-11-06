<?php

declare(strict_types=1);

namespace App\Modules\Activities\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Activities\Models\Activity;

/** Deny-by-default matrix check for Activity. @see SRS M07-01 … M07-03, NFR-02 */
final class ActivityPolicy extends ResourcePolicy
{
    protected string $model = Activity::class;
}
