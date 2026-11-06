<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Kernel\Crm\Contracts\PurchasingDirectory;
use App\Kernel\Documents\Contracts\VendorPurchaseOrders;
use App\Kernel\Identity\Models\User;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Procurement\Exceptions\ProcurementRejected;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\PurchaseRequest;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Goods / service receipts against ISSUED vendor POs; never more than ordered per PO line. A receipt can then be
 * billed in M01 (bill pre-filled from the receipt, carrying vendor_po_id + goods_receipt_id — M10-02).
 *
 * @see SRS M10-02, WF-02
 */
final class GoodsReceiptService implements PurchasingDirectory
{
    public function __construct(private readonly NumberingService $numbering, private readonly VendorPurchaseOrders $vendorPos) {}

    /** @param  array{received_on: string, kind: string, notes?: string|null, lines: list<array{po_line_id: int, quantity_received: string, condition?: string|null}>}  $data */
    public function record(int $vendorPoId, array $data, User $actor): GoodsReceipt
    {
        $po = $this->vendorPos->snapshot($vendorPoId) ?? throw new ProcurementRejected('Unknown vendor PO.');
        if (! in_array($po['status'], ['issued', 'accepted'], true)) {
            throw new ProcurementRejected('Goods can only be received against an issued vendor PO.');
        }
        $poLines = collect($po['lines'])->keyBy('id');

        return DB::transaction(function () use ($vendorPoId, $data, $actor, $po, $poLines): GoodsReceipt {
            DB::table('goods_receipts')->where('vendor_po_id', $vendorPoId)->lockForUpdate()->get();
            $grn = GoodsReceipt::query()->create(['company_id' => $actor->company_id, 'grn_no' => $this->numbering->allocate((int) $actor->company_id, 'GOODS_RECEIPT', (int) today()->format('Y'))['formatted'],
                'vendor_po_id' => $vendorPoId, 'vendor_id' => (int) $po['vendor_id'], 'received_on' => $data['received_on'], 'kind' => $data['kind'], 'notes' => $data['notes'] ?? null, 'status' => 'received', 'received_by' => $actor->id]);
            $any = false;
            foreach ($data['lines'] as $l) {
                $line = $poLines->get((int) $l['po_line_id']) ?? throw new ProcurementRejected('That line is not on this PO.');
                $qty = BigDecimal::of($l['quantity_received']);
                if ($qty->isZero()) {
                    continue;
                }
                $already = BigDecimal::of((string) (GoodsReceiptLine::query()->whereHas('receipt', fn ($q) => $q->where('vendor_po_id', $vendorPoId)->where('status', 'received'))->where('po_line_id', $line['id'])->sum('quantity_received') ?: '0'));
                if ($already->plus($qty)->isGreaterThan($line['quantity'])) {
                    throw new ProcurementRejected("Line {$line['line_no']}: receiving more than ordered ({$line['quantity']}).");
                }
                $grn->lines()->create(['po_line_id' => $line['id'], 'description' => $line['description'], 'quantity_ordered' => $line['quantity'], 'quantity_received' => (string) $qty, 'condition' => $l['condition'] ?? null]);
                $any = true;
            }
            if (! $any) {
                throw new ProcurementRejected('Enter the quantity received for at least one line.');
            }

            return $grn;
        });
    }

    public function receiptForBilling(int $goodsReceiptId): ?array
    {
        $grn = GoodsReceipt::query()->with('lines')->find($goodsReceiptId);
        if ($grn === null || $grn->status !== 'received') {
            return null;
        }
        $po = $this->vendorPos->snapshot($grn->vendor_po_id);
        $poLines = collect($po['lines'] ?? [])->keyBy('id');
        $pr = $po !== null && $po['purchase_request_id'] !== null ? PurchaseRequest::query()->with('lines')->find($po['purchase_request_id']) : null;
        $classes = $pr?->lines->pluck('capitalization_class', 'line_no')->all() ?? [];

        return ['goods_receipt_id' => $grn->id, 'grn_no' => $grn->grn_no, 'vendor_id' => $grn->vendor_id, 'vendor_po_id' => $grn->vendor_po_id,
            'lines' => $grn->lines->map(fn (GoodsReceiptLine $l): array => ['description' => $l->description, 'quantity' => (string) $l->quantity_received,
                'unit_price' => (string) ($poLines[$l->po_line_id]['unit_price'] ?? '0.00'), 'capitalization_class' => (string) ($classes[$poLines[$l->po_line_id]['line_no'] ?? 0] ?? 'EXPENSE')])->values()->all()];
    }
}
