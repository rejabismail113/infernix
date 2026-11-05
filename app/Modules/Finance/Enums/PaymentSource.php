<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

/** Who actually paid. Staff-paid expenses go through Claims (single expense impact, UAT-02). @see SRS M01-02, WF-03 */
enum PaymentSource: string
{
    case DirectCompany = 'DIRECT_COMPANY';
    case StaffPaidReimbursable = 'STAFF_PAID_REIMBURSABLE';
    case OwnerPaid = 'OWNER_PAID';
}
