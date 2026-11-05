<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/** @see SRS M01-01 */
enum InflowType: string
{
    case SalesProject = 'sales_project';
    case SalesTraining = 'sales_training';
    case Grant = 'grant';
    case OtherIncome = 'other_income';
    case CapitalInjection = 'capital_injection';
    case Loan = 'loan';
    case Refund = 'refund';
}
