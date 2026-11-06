<?php

declare(strict_types=1);

namespace App\Kernel\Crm\Contracts;

/**
 * M10 data M01 needs to pre-fill a vendor bill from a goods / service receipt (M10-02 audit chain).
 *
 * @see SRS M10-02, WF-02
 */
interface PurchasingDirectory
{
    /** @return array{goods_receipt_id: int, grn_no: string, vendor_id: int, vendor_po_id: int, lines: list<array{description: string, quantity: string, unit_price: string, capitalization_class: string}>}|null */
    public function receiptForBilling(int $goodsReceiptId): ?array;
}
