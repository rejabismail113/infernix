<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Incoming client PO / LOA (M03-05). @see SRS M03 */
final class ClientPurchaseOrder extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::ClientPo;
    }
}
