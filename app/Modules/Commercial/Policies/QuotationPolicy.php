<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Policies;

use App\Kernel\Identity\Policies\ResourcePolicy;
use App\Modules\Commercial\Models\Quotation;

/** Deny-by-default matrix check for Quotation. @see SRS M03, NFR-02, M11-05 */
final class QuotationPolicy extends ResourcePolicy
{
    protected string $model = Quotation::class;
}
