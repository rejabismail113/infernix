<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Outgoing vendor PO (M03-05; used by M10). @see SRS M03 */
final class VendorPurchaseOrder extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::VendorPo;
    }
}
