<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\CreditNote;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Commercial\Models\ReceiptDocument;
use App\Modules\Commercial\Models\VoucherDocument;
use Illuminate\Support\Facades\DB;

/** Document type → model class. @see SRS M03 */
final class DocumentRegistry
{
    /** @return class-string<CommercialDocument> */
    public static function modelFor(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Quotation => Quotation::class,
            DocumentType::Invoice => Invoice::class,
            DocumentType::CreditNote => CreditNote::class,
            DocumentType::Receipt => ReceiptDocument::class,
            DocumentType::PaymentVoucher => VoucherDocument::class,
        };
    }

    public static function find(int $id): ?CommercialDocument
    {
        $type = DB::table('commercial_documents')->where('id', $id)->value('type');

        return $type === null ? null : self::modelFor(DocumentType::from((string) $type))::query()->find($id);
    }
}
