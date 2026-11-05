<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/** @see SRS M01-09 */
enum TaxTreatment: string
{
    case PendingReview = 'pending_review';
    case Deductible = 'deductible';
    case NonDeductible = 'non_deductible';
    case Capital = 'capital';
}
