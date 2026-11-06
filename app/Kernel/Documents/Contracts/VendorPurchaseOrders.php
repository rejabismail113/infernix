<?php

declare(strict_types=1);

namespace App\Kernel\Documents\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * M03 vendor POs for M10 procurement: create a draft PO from an approved purchase request and read PO
 * snapshots for goods receipts / the audit chain. A vendor PO posts nothing (commitment only).
 *
 * @see SRS M03-05, M10-02, WF-02
 */
interface VendorPurchaseOrders
{
    /**
     * @param  array{vendor_id: int, project_id?: int|null, title: string, purchase_request_id: int, lines: list<array{description: string, quantity: string, unit_price: string}>}  $data
     * @return int the draft vendor PO id
     */
    public function createFromRequest(array $data, User $actor): int;

    /** @return array{id: int, number: string|null, status: string, vendor_id: int|null, vendor: string|null, total: string, purchase_request_id: int|null, project_id: int|null, url: string, lines: list<array{id: int, line_no: int, description: string, quantity: string, unit_price: string, amount: string}>}|null */
    public function snapshot(int $vendorPoId): ?array;

    /** @return list<array{id: int, label: string, vendor_id: int|null}> issued POs that can be received against */
    public function openOrders(): array;
}
