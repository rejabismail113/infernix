<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Documents\Contracts\VendorPurchaseOrders;
use App\Kernel\Identity\Models\User;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Models\DocumentLine;
use App\Modules\Commercial\Models\VendorPurchaseOrder;

/**
 * M03 side of the procurement chain: purchase request → vendor PO (draft, then the normal vendor PO approval
 * and issue). Vendor POs never post to the ledger.
 *
 * @see SRS M03-05, M10-02
 */
final class VendorPurchaseOrderService implements VendorPurchaseOrders
{
    public function __construct(private readonly CommercialDocumentService $documents) {}

    public function createFromRequest(array $data, User $actor): int
    {
        return $this->documents->createDraft(DocumentType::VendorPo, [
            'vendor_id' => $data['vendor_id'], 'project_id' => $data['project_id'] ?? null, 'title' => $data['title'],
            'source_type' => 'purchase_request', 'source_id' => $data['purchase_request_id'],
            'lines' => array_map(fn (array $l): array => ['description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']], $data['lines']),
        ], $actor)->id;
    }

    public function snapshot(int $vendorPoId): ?array
    {
        $po = VendorPurchaseOrder::query()->with('lines')->find($vendorPoId);
        if ($po === null) {
            return null;
        }

        return ['id' => $po->id, 'number' => $po->document_no, 'status' => $po->status->value, 'vendor_id' => $po->counterparty_id, 'vendor' => $po->counterparty_snapshot['name'] ?? null,
            'total' => (string) $po->total->getAmount(), 'purchase_request_id' => $po->source_type === 'purchase_request' ? $po->source_id : null, 'project_id' => $po->project_id,
            'url' => route('commercial.vendor-pos.show', $po->id),
            'lines' => $po->lines->map(fn (DocumentLine $l): array => ['id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => (string) $l->quantity,
                'unit_price' => (string) $l->unit_price->getAmount(), 'amount' => (string) $l->line_total->getAmount()])->values()->all()];
    }

    public function openOrders(): array
    {
        return VendorPurchaseOrder::query()->whereIn('status', [DocumentStatus::Issued->value, DocumentStatus::Accepted->value])
            ->orderByDesc('issue_date')->limit(300)->get()
            ->map(fn (VendorPurchaseOrder $po): array => ['id' => $po->id, 'label' => trim(($po->document_no ?? 'PO').' '.($po->counterparty_snapshot['name'] ?? '').' RM '.$po->total->getAmount()), 'vendor_id' => $po->counterparty_id])->values()->all();
    }
}
