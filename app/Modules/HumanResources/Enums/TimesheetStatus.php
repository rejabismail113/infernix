<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Enums;

use App\Kernel\StateMachine\TransitionTable;

/** @see SRS M04-02, M04-03, WF-05 */
enum TimesheetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Calculated = 'calculated';
    case Batched = 'batched';
    case Approved = 'approved';
    case Paid = 'paid';

    public static function transitions(): TransitionTable
    {
        return new TransitionTable([
            'draft' => ['submitted'],
            'rejected' => ['submitted'],
            'submitted' => ['verified', 'rejected'],
            'verified' => ['calculated', 'rejected'],
            'calculated' => ['batched'],
            'batched' => ['approved', 'calculated'],
            'approved' => ['paid'],
        ]);
    }
}
