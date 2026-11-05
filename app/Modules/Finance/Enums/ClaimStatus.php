<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

use App\Kernel\StateMachine\TransitionTable;

/** @see SRS WF-01, M01-03 */
enum ClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case FinancePayable = 'finance_payable';
    case Paid = 'paid';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public static function transitions(): TransitionTable
    {
        return new TransitionTable([
            'draft' => ['submitted', 'cancelled'],
            'submitted' => ['pending_approval'],
            'pending_approval' => ['approved', 'rejected'],
            'approved' => ['finance_payable'],
            'rejected' => ['submitted', 'cancelled'],
            'finance_payable' => ['paid'],
            'paid' => ['closed'],
        ]);
    }
}
