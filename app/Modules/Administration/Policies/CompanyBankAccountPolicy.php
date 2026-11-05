<?php

declare(strict_types=1);

namespace App\Modules\Administration\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Administration\Models\CompanyBankAccount;

/** @see SRS G-03, M03-08 */
final class CompanyBankAccountPolicy extends ResourcePolicy
{
    protected string $model = CompanyBankAccount::class;
}
