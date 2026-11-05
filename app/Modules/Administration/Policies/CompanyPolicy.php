<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\Company;

/** @see SRS G-03, M03-08 */
final class CompanyPolicy extends ResourcePolicy
{
    protected string $model = Company::class;
}
