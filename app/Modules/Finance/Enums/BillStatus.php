<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

use App\Kernel\StateMachine\TransitionTable;

/** @see SRS M01-05 */
enum BillStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public static function transitions(): TransitionTable
    {
        return new TransitionTable([
            'draft' => ['pending_approval', 'cancelled'],
            'pending_approval' => ['approved', 'draft'],
            'approved' => ['partially_paid', 'paid'],
            'partially_paid' => ['partially_paid', 'paid'],
        ]);
    }
}
