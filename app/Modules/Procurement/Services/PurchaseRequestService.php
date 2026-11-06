<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Services;

use App\Kernel\Approval\ApprovalFlow;
use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\VendorPurchaseOrders;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\Search\SearchIndexer;
use App\Modules\Procurement\Exceptions\ProcurementRejected;
use App\Modules\Procurement\Models\PurchaseRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Purchase requests (M10-02): draft → approval (matrix + maker-checker) → vendor PO (M03) → goods receipt →
 * vendor bill (M01) → payment. The PR itself never touches the ledger.
 *
 * @see SRS M10-02, WF-02, UAT-11
 */
final class PurchaseRequestService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ApprovalFlow $flow,
        private readonly VendorPurchaseOrders $vendorPos,
        private readonly SearchIndexer $search,
    ) {}

    /** @param  array{title: string, justification: string, vendor_id?: int|null, project_id?: int|null, activity_id?: int|null, needed_by?: string|null, lines: list<array{description: string, quantity: string, estimated_unit_price: string, capitalization_class?: string|null}>}  $data */
    public function save(?PurchaseRequest $pr, array $data, User $actor): PurchaseRequest
    {
        if ($pr !== null && ! in_array($pr->status, ['draft', 'rejected'], true)) {
            throw new ProcurementRejected('Only a draft or rejected request can be edited.');
        }
        if ($data['lines'] === []) {
            throw new ProcurementRejected('Add at least one line.');
        }

        return DB::transaction(function () use ($pr, $data, $actor): PurchaseRequest {
            $fields = ['title' => $data['title'], 'justification' => $data['justification'], 'vendor_id' => $data['vendor_id'] ?? null, 'project_id' => $data['project_id'] ?? null,
                'activity_id' => $data['activity_id'] ?? null, 'needed_by' => $data['needed_by'] ?? null];
            if ($pr === null) {
                $pr = PurchaseRequest::query()->create($fields + ['company_id' => $actor->company_id, 'requested_by' => $actor->id, 'status' => 'draft',
                    'pr_no' => $this->numbering->allocate((int) $actor->company_id, 'PURCHASE_REQUEST', (int) today()->format('Y'))['formatted']]);
            } else {
                $pr->forceFill($fields + ['status' => 'draft', 'rejection_reason' => null])->save();
                $pr->lines()->delete();
            }
            $total = Money::zero();
            foreach (array_values($data['lines']) as $i => $l) {
                $amount = Money::myr((string) BigDecimal::of($l['quantity'])->multipliedBy($l['estimated_unit_price'])->toScale(2, RoundingMode::HALF_UP));
                $pr->lines()->create(['line_no' => $i + 1, 'description' => $l['description'], 'quantity' => $l['quantity'], 'estimated_unit_price' => Money::myr($l['estimated_unit_price']),
                    'estimated_amount' => $amount, 'capitalization_class' => $l['capitalization_class'] ?? 'EXPENSE']);
                $total = $total->plus($amount);
            }
            $pr->forceFill(['estimated_total' => $total])->save();
            $this->search->index('purchase_request', $pr->id, "{$pr->pr_no} {$pr->title}", $pr->justification, 'Procurement');

            return $pr->refresh();
        });
    }

    public function submit(PurchaseRequest $pr, User $actor): void
    {
        DB::transaction(function () use ($pr, $actor): void {
            /** @var PurchaseRequest $locked */
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            if ($locked->status === 'rejected') {
                $locked->forceFill(['status' => 'draft'])->save();
            }
            $this->flow->submit($locked, 'purchase_request', $locked->estimated_total, $actor);
        });
    }

    public function approve(PurchaseRequest $pr, User $approver, ?string $overrideReason = null): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $approver, $overrideReason): PurchaseRequest {
            /** @var PurchaseRequest $locked */
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            $this->flow->approve($locked, 'purchase_request', $locked->estimated_total, $approver, $overrideReason, fn () => null);

            return $locked->refresh();
        });
    }

    public function reject(PurchaseRequest $pr, User $actor, string $reason): void
    {
        DB::transaction(function () use ($pr, $actor, $reason): void {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);
            $this->flow->reject($locked, 'purchase_request', $actor, $reason);
            $locked->forceFill(['rejection_reason' => $reason])->save();
        });
    }

    public function cancel(PurchaseRequest $pr, User $actor, string $reason): void
    {
        if (in_array($pr->status, ['ordered', 'cancelled'], true)) {
            throw new ProcurementRejected('An ordered or cancelled request cannot be cancelled.');
        }
        $pr->forceFill(['status' => 'cancelled'])->save();
        Audit::log('cancel', $pr, null, null, null, $reason, $actor);
    }

    /** Raise the vendor PO (M03 draft) from an approved request; the PO then follows its own approval/issue. */
    public function raisePurchaseOrder(PurchaseRequest $pr, int $vendorId, User $actor): int
    {
        return DB::transaction(function () use ($pr, $vendorId, $actor): int {
            /** @var PurchaseRequest $locked */
            $locked = PurchaseRequest::query()->lockForUpdate()->with('lines')->findOrFail($pr->id);
            if ($locked->status !== 'approved') {
                throw new ProcurementRejected('Only an approved request can be turned into a vendor PO.');
            }
            $poId = $this->vendorPos->createFromRequest(['vendor_id' => $vendorId, 'project_id' => $locked->project_id, 'title' => "{$locked->pr_no}: {$locked->title}", 'purchase_request_id' => $locked->id,
                'lines' => $locked->lines->map(fn ($l): array => ['description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->estimated_unit_price->getAmount()])->values()->all()], $actor);
            $locked->forceFill(['status' => 'ordered', 'vendor_po_id' => $poId, 'vendor_id' => $vendorId])->save();
            Audit::log('order', $locked, null, null, ['vendor_po_id' => $poId], null, $actor);

            return $poId;
        });
    }
}
