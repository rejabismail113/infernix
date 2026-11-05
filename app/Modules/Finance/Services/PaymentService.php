<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Finance\Enums\BillStatus;
use App\Modules\Finance\Enums\ClaimStatus;
use App\Modules\Finance\Enums\PaymentStatus;
use App\Modules\Finance\Events\PaymentPosted;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Posting\PaymentPosting;
use Brick\Money\Money as BrickMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Payments & payment vouchers: draft → pending approval → approved (maker-checker) → paid (posted).
 * Allocations settle bills/claims partially or fully and may never exceed a target's balance
 * (service check + DB trigger). After posting a PDF payment voucher is generated and frozen.
 *
 * @see SRS M01-05, M03-06, M10-03, UAT-02, UAT-11, §5.1
 */
final class PaymentService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly PostingService $posting,
        private readonly PaymentPosting $recipe,
        private readonly ApprovalService $approvals,
        private readonly BillService $bills,
        private readonly ClaimService $claims,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * @param  array{payment_date: string, bank_account_id: int, method: string, reference?: string|null, payee_type: string, payee_id?: int|null, payee_name: string, memo?: string|null, allocations: list<array{target_type: string, target_id: int, amount: string}>}  $data
     */
    public function create(array $data, User $actor): Payment
    {
        if ($data['allocations'] === []) {
            throw new PostingRejected('Allocate the payment to at least one bill or claim.');
        }
        $total = Money::zero();
        foreach ($data['allocations'] as $a) {
            $amount = Money::myr($a['amount']);
            if (! $amount->isPositive()) {
                throw new PostingRejected('Allocation amounts must be positive.');
            }
            $total = $total->plus($amount);
        }

        return DB::transaction(function () use ($data, $actor, $total): Payment {
            $date = Carbon::parse($data['payment_date']);
            $payment = Payment::query()->create([
                'company_id' => $actor->company_id,
                'payment_no' => $this->numbering->allocate((int) $actor->company_id, 'PAYMENT_VOUCHER', (int) $date->format('Y'))['formatted'],
                'payment_date' => $date->toDateString(), 'bank_account_id' => $data['bank_account_id'], 'method' => $data['method'],
                'reference' => $data['reference'] ?? null, 'payee_type' => $data['payee_type'], 'payee_id' => $data['payee_id'] ?? null,
                'payee_name' => $data['payee_name'], 'amount' => $total, 'memo' => $data['memo'] ?? null, 'created_by' => $actor->id,
            ]);
            foreach ($data['allocations'] as $a) {
                $this->assertAllocatable($a['target_type'], (int) $a['target_id'], Money::myr($a['amount']), $data['payee_type'], $data['payee_id'] ?? null);
                try {
                    DB::transaction(fn () => PaymentAllocation::query()->create(['payment_id' => $payment->id, 'target_type' => $a['target_type'], 'target_id' => (int) $a['target_id'], 'amount' => Money::myr($a['amount'])]));
                } catch (QueryException $e) {
                    throw new PostingRejected(str_contains($e->getMessage(), 'exceeds') ? 'Allocation exceeds what is still owed on '.$a['target_type'].' #'.$a['target_id'].' (it may already be in another payment).' : 'Allocation rejected: '.$e->getMessage());
                }
            }

            return $payment->refresh();
        });
    }

    public function submit(Payment $payment, User $actor): Payment
    {
        PaymentStatus::transitions()->assert('Payment', $payment->status, PaymentStatus::PendingApproval);
        if ($this->approvals->levels('payment', $payment->amount) === []) {
            throw new PostingRejected('No approval matrix is configured for payments.');
        }
        $payment->forceFill(['status' => PaymentStatus::PendingApproval, 'current_level' => 1])->save();
        Audit::log('submit', $payment, null, null, ['status' => 'pending_approval'], null, $actor);

        return $payment;
    }

    public function approve(Payment $payment, User $approver, ?string $overrideReason = null): Payment
    {
        return DB::transaction(function () use ($payment, $approver, $overrideReason): Payment {
            /** @var Payment $p */
            $p = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($p->status !== PaymentStatus::PendingApproval) {
                throw new PostingRejected('Only payments pending approval can be approved.');
            }
            $decision = $this->approvals->authorize($approver, 'payment', $p->amount, $p->current_level, $p->created_by, $overrideReason, $this->approvals->priorApprovers('payment', $p->id));
            $this->approvals->record('payment', $p->id, $decision, $approver);
            Audit::log('approve', $p, null, ['level' => $p->current_level], ['level' => $decision->level, 'final' => $decision->finalLevel, 'delegation_id' => $decision->delegationId], $overrideReason, $approver);
            if ($decision->ownerOverride) {
                $p->forceFill(['owner_override' => true, 'override_reason' => $overrideReason])->save();
                $this->approvals->auditOverride($p, $approver, (string) $overrideReason);
            }
            if (! $decision->finalLevel) {
                $p->forceFill(['current_level' => $p->current_level + 1])->save();

                return $p;
            }
            $p->forceFill(['status' => PaymentStatus::Approved, 'approved_by' => $approver->id, 'approved_at' => now()])->save();

            return $p;
        });
    }

    /** Release the payment: post Dr payables / Cr bank and settle the targets. */
    public function post(Payment $payment, User $actor): Payment
    {
        $posted = DB::transaction(function () use ($payment, $actor): Payment {
            /** @var Payment $p */
            $p = Payment::query()->lockForUpdate()->with('allocations')->findOrFail($payment->id);
            PaymentStatus::transitions()->assert('Payment', $p->status, PaymentStatus::Paid);
            $journal = $this->posting->post($this->recipe->draft($p), $actor);
            foreach ($p->allocations as $a) {
                if ($a->target_type === 'bill') {
                    $this->bills->applyPayment(Bill::query()->lockForUpdate()->findOrFail($a->target_id), $a->amount);
                } elseif ($a->target_type === 'claim') {
                    $this->claims->applyPayment(Claim::query()->lockForUpdate()->findOrFail($a->target_id), $a->amount, $actor);
                }
            }
            $p->forceFill(['status' => PaymentStatus::Paid, 'journal_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now()])->save();
            DB::afterCommit(fn () => Event::dispatch(new PaymentPosted($p->id)));

            return $p;
        });
        // PDF rendering happens outside the posting transaction (CLAUDE.md rule 6).
        $this->vouchers->generate($posted->refresh(), $actor);

        return $posted->refresh();
    }

    public function cancel(Payment $payment, User $actor, string $reason): void
    {
        PaymentStatus::transitions()->assert('Payment', $payment->status, PaymentStatus::Cancelled);
        $payment->forceFill(['status' => PaymentStatus::Cancelled])->save();
        Audit::log('cancel', $payment, null, null, null, $reason, $actor);
    }

    /** Remaining balance after all non-cancelled payments' allocations (what may still be allocated). */
    public function unallocated(string $type, int $id): BrickMoney
    {
        $target = $type === 'bill' ? Bill::query()->findOrFail($id) : Claim::query()->findOrFail($id);
        $ceiling = $target instanceof Bill ? $target->total_amount : $target->approved_amount;
        $allocated = (string) DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.status', '!=', 'cancelled')->where('target_type', $type)->where('target_id', $id)->sum('payment_allocations.amount');

        return $ceiling->minus(Money::fromDb($allocated === '' ? '0' : $allocated));
    }

    private function assertAllocatable(string $type, int $id, BrickMoney $amount, string $payeeType, ?int $payeeId): void
    {
        if ($type === 'bill') {
            $bill = Bill::query()->find($id) ?? throw new PostingRejected("Bill #{$id} does not exist.");
            if (! in_array($bill->status, [BillStatus::Approved, BillStatus::PartiallyPaid], true)) {
                throw new PostingRejected("Bill {$bill->bill_no} is not approved for payment.");
            }
            if ($payeeType !== 'vendor' || $payeeId !== $bill->vendor_id) {
                throw new PostingRejected("Bill {$bill->bill_no} can only be paid to its vendor.");
            }
        } elseif ($type === 'claim') {
            $claim = Claim::query()->find($id) ?? throw new PostingRejected("Claim #{$id} does not exist.");
            if ($claim->status !== ClaimStatus::FinancePayable) {
                throw new PostingRejected("Claim {$claim->claim_no} is not approved for payment.");
            }
            if ($payeeType !== 'staff' || $payeeId !== $claim->staff_user_id) {
                throw new PostingRejected("Claim {$claim->claim_no} can only be paid to the claimant.");
            }
        } else {
            throw new PostingRejected("Payments to {$type} are not supported yet.");
        }
        if ($amount->isGreaterThan($this->unallocated($type, $id))) {
            throw new PostingRejected("Allocation exceeds what is still owed on {$type} #{$id} (it may already be in another payment).");
        }
    }
}
