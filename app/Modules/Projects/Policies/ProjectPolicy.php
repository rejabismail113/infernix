<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Projects\Models\Project;

/** @see SRS M02-01, NFR-02 */
final class ProjectPolicy extends ResourcePolicy
{
    protected string $model = Project::class;
}
