<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Quotation (M03-01, M03-02). @see SRS M03 */
final class Quotation extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::Quotation;
    }
}
