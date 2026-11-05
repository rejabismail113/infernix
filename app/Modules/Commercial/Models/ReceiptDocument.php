<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Register row + PDF of a posted receipt (M03-04). @see SRS M03 */
final class ReceiptDocument extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::Receipt;
    }
}
