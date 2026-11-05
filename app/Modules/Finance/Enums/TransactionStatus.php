<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

use App\Kernel\StateMachine\TransitionTable;

/** @see SRS M01-01, M01-02 */
enum TransactionStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';

    public static function transitions(): TransitionTable
    {
        return new TransitionTable([
            'draft' => ['posted', 'cancelled'],
            'posted' => ['reversed'],
        ]);
    }
}
