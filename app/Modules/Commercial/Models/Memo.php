<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Staff memo (M03-06). @see SRS M03 */
final class Memo extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::Memo;
    }
}
