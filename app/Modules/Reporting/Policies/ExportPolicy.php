<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Reporting\Models\Export;

/** Exports are visible to their requester only (scope own). @see SRS M11-05, NFR-02 */
final class ExportPolicy extends ResourcePolicy
{
    protected string $model = Export::class;
}
