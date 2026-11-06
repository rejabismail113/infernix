<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Credentials\Models\JmlChecklist;

/** @see SRS M13-04, NFR-02 */
final class JmlChecklistPolicy extends ResourcePolicy
{
    protected string $model = JmlChecklist::class;
}
