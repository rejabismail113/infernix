<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Finance\Models\Transaction;

/** @see SRS M01, NFR-02 */
final class TransactionPolicy extends ResourcePolicy
{
    protected string $model = Transaction::class;
}
