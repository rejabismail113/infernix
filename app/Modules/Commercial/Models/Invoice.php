<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Invoice (M03-03). @see SRS M03 */
final class Invoice extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::Invoice;
    }
}
