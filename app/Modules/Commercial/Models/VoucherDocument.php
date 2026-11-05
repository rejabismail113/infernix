<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Register row + PDF of a posted payment (M03-06). @see SRS M03 */
final class VoucherDocument extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::PaymentVoucher;
    }
}
