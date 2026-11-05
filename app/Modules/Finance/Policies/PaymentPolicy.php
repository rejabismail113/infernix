<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Finance\Models\Payment;

/** @see SRS M01, NFR-02 */
final class PaymentPolicy extends ResourcePolicy
{
    protected string $model = Payment::class;
}
