<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

use App\Kernel\StateMachine\TransitionTable;

/** @see SRS M01-05 */
enum PaymentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public static function transitions(): TransitionTable
    {
        return new TransitionTable([
            'draft' => ['pending_approval', 'cancelled'],
            'pending_approval' => ['approved', 'draft', 'cancelled'],
            'approved' => ['paid', 'cancelled'],
        ]);
    }
}
