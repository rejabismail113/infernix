<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Projects\Models\Lead;

/** @see SRS M02-02, NFR-02 */
final class LeadPolicy extends ResourcePolicy
{
    protected string $model = Lead::class;
}
