<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Enums;

/** Invoice settlement (receipts + credit notes) — M03-03. */
enum SettlementStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
}
