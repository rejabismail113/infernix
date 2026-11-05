<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Finance\Enums\BillStatus;
use App\Modules\Finance\Enums\CapitalizationClass;
use App\Modules\Finance\Events\AssetQualifyingPurchaseRecorded;
use App\Modules\Finance\Events\BillApproved;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Posting\BillApprovalPosting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Vendor bills: draft → pending approval → approved (Dr expense/asset, Cr AP) → partially paid → paid.
 *
 * @see SRS M01-05, M10-03, WF-02
 */
final class BillService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly PostingService $posting,
        private readonly BillApprovalPosting $recipe,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array{vendor_id: int, vendor_invoice_no: string, bill_date: string, due_date: string, description?: string|null, lines: list<array{description: string, quantity: string, unit_price: string, category_id?: int|null, capitalization_class?: string|null, project_id?: int|null}>}  $data
     */
    public function create(array $data, User $actor): Bill
    {
        $duplicate = Bill::query()->where('vendor_id', $data['vendor_id'])->whereRaw('lower(vendor_invoice_no) = ?', [mb_strtolower(trim($data['vendor_invoice_no']))])->first();
        if ($duplicate !== null) {
            throw new PostingRejected("This vendor invoice is already recorded as {$duplicate->bill_no}; it cannot be entered (or paid) twice.");
        }

        return DB::transaction(function () use ($data, $actor): Bill {
            $date = Carbon::parse($data['bill_date']);
            $bill = Bill::query()->create([
                'company_id' => $actor->company_id,
                'bill_no' => $this->numbering->allocate((int) $actor->company_id, 'BILL', (int) $date->format('Y'))['formatted'],
                'vendor_id' => $data['vendor_id'], 'vendor_invoice_no' => trim($data['vendor_invoice_no']), 'bill_date' => $date->toDateString(),
                'due_date' => Carbon::parse($data['due_date'])->toDateString(), 'description' => $data['description'] ?? null, 'created_by' => $actor->id,
            ]);
            $total = Money::zero();
            foreach (array_values($data['lines']) as $i => $l) {
                $amount = BrickMoney::of(BigDecimal::of($l['quantity'])->multipliedBy($l['unit_price'])->toScale(2, RoundingMode::HALF_UP), 'MYR');
                $bill->lines()->create([
                    'line_no' => $i + 1, 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => Money::myr($l['unit_price']),
                    'amount' => $amount, 'category_id' => $l['category_id'] ?? null,
                    'capitalization_class' => $l['capitalization_class'] ?? CapitalizationClass::Expense->value, 'project_id' => $l['project_id'] ?? null,
                ]);
                $total = $total->plus($amount);
            }
            $bill->forceFill(['total_amount' => $total, 'status' => BillStatus::Draft])->save();

            return $bill->refresh();
        });
    }

    public function submit(Bill $bill, User $actor): Bill
    {
        BillStatus::transitions()->assert('Bill', $bill->status, BillStatus::PendingApproval);
        if ($this->approvals->levels('bill', $bill->total_amount) === []) {
            throw new PostingRejected('No approval matrix is configured for bills.');
        }
        $bill->forceFill(['status' => BillStatus::PendingApproval, 'current_level' => 1])->save();
        Audit::log('submit', $bill, null, null, ['status' => 'pending_approval'], null, $actor);

        return $bill;
    }

    public function approve(Bill $bill, User $approver, ?string $overrideReason = null): Bill
    {
        return DB::transaction(function () use ($bill, $approver, $overrideReason): Bill {
            /** @var Bill $b */
            $b = Bill::query()->lockForUpdate()->with(['lines', 'vendor'])->findOrFail($bill->id);
            if ($b->status !== BillStatus::PendingApproval) {
                throw new PostingRejected('Only bills pending approval can be approved.');
            }
            $decision = $this->approvals->authorize($approver, 'bill', $b->total_amount, $b->current_level, $b->created_by, $overrideReason, $this->approvals->priorApprovers('bill', $b->id));
            $this->approvals->record('bill', $b->id, $decision, $approver);
            if ($decision->ownerOverride) {
                $this->approvals->auditOverride($b, $approver, (string) $overrideReason);
            }
            Audit::log('approve', $b, null, ['level' => $b->current_level], ['level' => $decision->level, 'final' => $decision->finalLevel], $overrideReason, $approver);
            if (! $decision->finalLevel) {
                $b->forceFill(['current_level' => $b->current_level + 1])->save();

                return $b;
            }
            BillStatus::transitions()->assert('Bill', $b->status, BillStatus::Approved);
            $journal = $this->posting->post($this->recipe->draft($b, $b->bill_date), $approver);
            $b->forceFill(['status' => BillStatus::Approved, 'journal_id' => $journal->id, 'approved_by' => $approver->id, 'approved_at' => now()])->save();
            $capital = $b->lines->filter(fn ($l) => $l->capitalization_class === CapitalizationClass::CapitalAsset);
            DB::afterCommit(function () use ($b, $capital): void {
                Event::dispatch(new BillApproved($b->id));
                foreach ($capital as $line) {
                    Event::dispatch(new AssetQualifyingPurchaseRecorded('bill', $b->id, $line->id, $line->description, Money::toDb($line->amount), $b->bill_date->toDateString()));
                }
            });

            return $b;
        });
    }

    public function returnToDraft(Bill $bill, User $actor, string $reason): void
    {
        BillStatus::transitions()->assert('Bill', $bill->status, BillStatus::Draft);
        $bill->forceFill(['status' => BillStatus::Draft, 'current_level' => 0])->save();
        $this->approvals->reset('bill', $bill->id);
        Audit::log('return', $bill, null, null, null, $reason, $actor);
    }

    public function cancel(Bill $bill, User $actor, string $reason): void
    {
        BillStatus::transitions()->assert('Bill', $bill->status, BillStatus::Cancelled);
        $bill->forceFill(['status' => BillStatus::Cancelled])->save();
        Audit::log('cancel', $bill, null, null, null, $reason, $actor);
    }

    /** Called by PaymentService inside the payment transaction. */
    public function applyPayment(Bill $bill, BrickMoney $amount): void
    {
        $paid = $bill->paid_amount->plus($amount);
        if ($paid->isGreaterThan($bill->total_amount)) {
            throw new PostingRejected("Payment exceeds the outstanding amount of bill {$bill->bill_no}.");
        }
        $to = $paid->isEqualTo($bill->total_amount) ? BillStatus::Paid : BillStatus::PartiallyPaid;
        BillStatus::transitions()->assert('Bill', $bill->status, $to);
        $bill->forceFill(['paid_amount' => $paid, 'status' => $to])->save();
    }
}
