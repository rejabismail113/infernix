<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Finance\Models\Journal;

/** @see SRS M01, NFR-02 */
final class JournalPolicy extends ResourcePolicy
{
    protected string $model = Journal::class;
}
