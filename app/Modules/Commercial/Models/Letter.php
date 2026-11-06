<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** General letter (M03-06). @see SRS M03 */
final class Letter extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::Letter;
    }
}
