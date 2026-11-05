<?php

declare(strict_types=1);

namespace App\Modules\Finance\Events;

/** @see docs/ARCHITECTURE.md §4 */
final class PaymentPosted
{
    public function __construct(public readonly int $paymentId) {}
}
