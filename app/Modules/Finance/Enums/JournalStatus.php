<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

use App\Kernel\StateMachine\TransitionTable;

/** @see SRS M01-06 */
enum JournalStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    public static function transitions(): TransitionTable
    {
        return new TransitionTable([
            'draft' => ['posted'],
            'posted' => ['reversed'],
        ]);
    }
}
