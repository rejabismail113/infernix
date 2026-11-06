<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Training certificate with public verification (M03-06). @see SRS M03 */
final class Certificate extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::Certificate;
    }
}
