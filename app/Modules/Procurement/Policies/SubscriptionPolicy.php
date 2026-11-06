<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Procurement\Models\Subscription;

/** Deny-by-default matrix check for Subscription. @see SRS M10-01 … M10-05, NFR-02 */
final class SubscriptionPolicy extends ResourcePolicy
{
    protected string $model = Subscription::class;
}
