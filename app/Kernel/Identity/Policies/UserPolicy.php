<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Policies;

use App\Kernel\Identity\Models\User;

/** @see SRS G-02, M12-01, M12-03 */
final class UserPolicy extends ResourcePolicy
{
    protected string $model = User::class;
}
