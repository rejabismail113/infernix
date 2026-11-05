<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Credit note against an invoice (M03-07). @see SRS M03 */
final class CreditNote extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::CreditNote;
    }
}
