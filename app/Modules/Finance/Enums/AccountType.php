<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/** @see SRS M01-06 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
}
