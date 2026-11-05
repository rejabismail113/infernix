<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/** @see SRS M01-10, M05-02 */
enum CapitalizationClass: string
{
    case CapitalAsset = 'CAPITAL_ASSET';
    case LowValueEquipment = 'LOW_VALUE_EQUIPMENT';
    case Consumable = 'CONSUMABLE';
    case Expense = 'EXPENSE';
    case OwnerDrawing = 'OWNER_DRAWING';

    /** Drawings never reach the P&L; capital assets are balance-sheet (no expense line). */
    public function hitsProfitAndLoss(): bool
    {
        return ! in_array($this, [self::CapitalAsset, self::OwnerDrawing], true);
    }
}
