<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\NotificationRule;

/** @see SRS G-05 */
final class NotificationRulePolicy extends ResourcePolicy
{
    protected string $model = NotificationRule::class;
}
