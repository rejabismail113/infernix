<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Approval\MakerCheckerViolation;
use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Finance\Enums\ClaimStatus;
use App\Modules\Finance\Events\ClaimApproved;
use App\Modules\Finance\Events\ClaimPaid;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\ClaimLine;
use App\Modules\Finance\Posting\ClaimApprovalPosting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Staff reimbursement workflow (WF-01): Draft → Submitted → PendingApproval → Approved/Rejected →
 * FinancePayable → Paid → Closed. Rejected claims are corrected and resubmitted. Receipts are mandatory
 * per claim type. Approval follows the matrix with delegation; the claimant can never approve their own
 * claim (Owner override with reason only). Approval posts the single expense journal.
 *
 * @see SRS M01-03, M01-04, WF-01, UAT-02, UAT-11, §2.1
 */
final class ClaimService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly PostingService $posting,
        private readonly ClaimApprovalPosting $recipe,
        private readonly ApprovalService $approvals,
        private readonly DocumentStore $documents,
    ) {}

    /**
     * @param  array{purpose: string, cost_centre_id?: int|null, project_id?: int|null, lines: list<array<string, mixed>>}  $data
     */
    public function create(User $staff, array $data, User $actor): Claim
    {
        return DB::transaction(function () use ($staff, $data, $actor): Claim {
            $no = $this->numbering->allocate((int) $actor->company_id, 'CLAIM', (int) now()->format('Y'))['formatted'];
            $claim = Claim::query()->create([
                'company_id' => $actor->company_id, 'claim_no' => $no, 'staff_user_id' => $staff->id, 'purpose' => $data['purpose'],
                'cost_centre_id' => $data['cost_centre_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'created_by' => $actor->id,
            ]);
            $this->syncLines($claim, $data['lines']);

            return $claim->refresh();
        });
    }

    /**
     * Edit while draft or rejected. Lines with an `id` are updated (a corrected rejected line returns to
     * pending), lines without one are added, missing ones are removed.
     *
     * @param  array{purpose?: string, cost_centre_id?: int|null, project_id?: int|null, lines: list<array<string, mixed>>}  $data
     */
    public function update(Claim $claim, array $data, User $actor): Claim
    {
        if (! $claim->isEditableBy($actor)) {
            throw new PostingRejected('Only draft or rejected claims can be edited, by the claimant.');
        }

        return DB::transaction(function () use ($claim, $data): Claim {
            $claim->fill(array_intersect_key($data, array_flip(['purpose', 'cost_centre_id', 'project_id'])))->save();
            $this->syncLines($claim, $data['lines']);

            return $claim->refresh();
        });
    }

    public function submit(Claim $claim, User $actor): Claim
    {
        return DB::transaction(function () use ($claim, $actor): Claim {
            /** @var Claim $c */
            $c = Claim::query()->lockForUpdate()->with('lines.claimType')->findOrFail($claim->id);
            if (! $c->isOwnedBy($actor)) {
                throw new PostingRejected('Only the claimant can submit this claim.');
            }
            ClaimStatus::transitions()->assert('Claim', $c->status, ClaimStatus::Submitted);
            if ($c->lines->isEmpty()) {
                throw new PostingRejected('Add at least one line.');
            }
            $missing = [];
            foreach ($c->lines as $line) {
                if ($line->claimType->code === 'mileage') {
                    $this->priceMileage($line);
                }
                if ($line->claimType->receipt_required && ! $this->documents->hasLinked('claim_line', $line->id, 'receipt')) {
                    $missing[] = 'line '.$line->line_no;
                }
                if ($line->claimType->per_claim_limit !== null && $line->amount->isGreaterThan($line->claimType->per_claim_limit)) {
                    throw new PostingRejected("Line {$line->line_no} exceeds the {$line->claimType->name} limit of RM ".$line->claimType->per_claim_limit->getAmount().'.');
                }
            }
            if ($missing !== []) {
                throw new PostingRejected('A receipt is required for '.implode(', ', $missing).'.');
            }
            $total = $this->recalculate($c);
            if ($this->approvals->levels('claim', $total) === []) {
                throw new PostingRejected('No approval matrix is configured for claims.');
            }
            $this->transition($c, ClaimStatus::Submitted, $actor);
            $c->forceFill(['submitted_at' => now(), 'rejection_reason' => null])->save();
            $c->lines()->where('status', 'rejected')->update(['status' => 'pending', 'rejection_reason' => null]);
            $this->transition($c, ClaimStatus::PendingApproval, $actor);
            $c->forceFill(['current_level' => 1])->save();

            return $c;
        });
    }

    public function approve(Claim $claim, User $approver, ?string $overrideReason = null): Claim
    {
        return DB::transaction(function () use ($claim, $approver, $overrideReason): Claim {
            /** @var Claim $c */
            $c = Claim::query()->lockForUpdate()->with(['lines.claimType', 'staff'])->findOrFail($claim->id);
            if ($c->status !== ClaimStatus::PendingApproval) {
                throw new PostingRejected('Only claims pending approval can be approved.');
            }
            // Maker-checker: neither the claimant nor whoever keyed the claim on their behalf may approve it
            // (an Owner may override with a recorded reason — UAT-11).
            $keyedByApprover = $c->created_by === $approver->id && $c->created_by !== $c->staff_user_id;
            if ($keyedByApprover && ! ($approver->isOwner() && trim((string) $overrideReason) !== '')) {
                throw new MakerCheckerViolation;
            }
            $prior = $c->approvals()->where('decision', 'approved')->where('decided_at', '>=', $c->submitted_at ?? now()->subCentury())->pluck('approver_id')->map(fn ($v): int => (int) $v)->all();
            $decision = $this->approvals->authorize($approver, 'claim', $c->total_amount, $c->current_level, $c->staff_user_id, $overrideReason, $prior);
            $override = $decision->ownerOverride || $keyedByApprover;

            $c->approvals()->create([
                'level' => $decision->level, 'approver_id' => $approver->id, 'decision' => 'approved', 'delegation_id' => $decision->delegationId,
                'owner_override' => $override, 'reason' => $override ? $overrideReason : null, 'decided_at' => now(),
            ]);
            if ($override) {
                $this->approvals->auditOverride($c, $approver, (string) $overrideReason);
            }
            Audit::log('approve', $c, null, ['level' => $c->current_level], ['level' => $decision->level, 'final' => $decision->finalLevel, 'delegation_id' => $decision->delegationId], $overrideReason, $approver);

            if (! $decision->finalLevel) {
                $c->forceFill(['current_level' => $c->current_level + 1])->save();

                return $c;
            }

            $this->transition($c, ClaimStatus::Approved, $approver);
            $c->lines()->where('status', 'pending')->update(['status' => 'approved']);
            $c->load('lines.claimType');
            $journal = $this->posting->post($this->recipe->draft($c, today()), $approver);
            $approved = $c->lines->where('status', 'approved')->reduce(fn (?BrickMoney $sum, ClaimLine $l) => $sum === null ? $l->amount : $sum->plus($l->amount));
            $c->forceFill(['journal_id' => $journal->id, 'approved_amount' => $approved ?? Money::zero(), 'approved_at' => now(), 'approved_by' => $approver->id])->save();
            $this->transition($c, ClaimStatus::FinancePayable, $approver);
            DB::afterCommit(fn () => Event::dispatch(new ClaimApproved($c->id)));

            return $c;
        });
    }

    /**
     * @param  array<int, string>  $lineReasons  line id => reason (lines to correct)
     */
    public function reject(Claim $claim, User $approver, string $reason, array $lineReasons = []): Claim
    {
        return DB::transaction(function () use ($claim, $approver, $reason, $lineReasons): Claim {
            /** @var Claim $c */
            $c = Claim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($c->status !== ClaimStatus::PendingApproval) {
                throw new PostingRejected('Only claims pending approval can be rejected.');
            }
            if ($c->isOwnedBy($approver) && ! $approver->isOwner()) {
                throw new MakerCheckerViolation('You cannot decide on your own claim.');
            }
            $this->approvals->authorize($approver, 'claim', $c->total_amount, $c->current_level, null);
            foreach ($lineReasons as $lineId => $lineReason) {
                $c->lines()->whereKey($lineId)->update(['status' => 'rejected', 'rejection_reason' => $lineReason]);
            }
            $c->approvals()->create(['level' => $c->current_level, 'approver_id' => $approver->id, 'decision' => 'rejected', 'reason' => $reason, 'decided_at' => now()]);
            $c->forceFill(['rejection_reason' => $reason, 'current_level' => 0])->save();
            $this->transition($c, ClaimStatus::Rejected, $approver, $reason);

            return $c;
        });
    }

    /** Called by PaymentService inside the payment transaction. */
    public function applyPayment(Claim $claim, BrickMoney $amount, User $actor): void
    {
        $paid = $claim->paid_amount->plus($amount);
        if ($paid->isGreaterThan($claim->approved_amount)) {
            throw new PostingRejected("Payment exceeds the outstanding amount of claim {$claim->claim_no}.");
        }
        $claim->forceFill(['paid_amount' => $paid])->save();
        if ($paid->isEqualTo($claim->approved_amount)) {
            $this->transition($claim, ClaimStatus::Paid, $actor);
            DB::afterCommit(fn () => Event::dispatch(new ClaimPaid($claim->id)));
        }
    }

    public function close(Claim $claim, User $actor): void
    {
        $this->transition($claim, ClaimStatus::Closed, $actor);
    }

    public function cancel(Claim $claim, User $actor, string $reason): void
    {
        $this->transition($claim, ClaimStatus::Cancelled, $actor, $reason);
    }

    /** Mileage = distance × the rate version in force on the travel date (snapshotted on the line). */
    public function priceMileage(ClaimLine $line): void
    {
        $type = $line->claimType;
        $rate = $type->rateOn($line->incurred_on) ?? throw new PostingRejected("No mileage rate is configured for {$line->incurred_on->toDateString()}.");
        if ($line->distance_km === null || BigDecimal::of($line->distance_km)->isLessThanOrEqualTo(0)) {
            throw new PostingRejected("Line {$line->line_no}: enter the distance in km.");
        }
        $amount = BigDecimal::of($line->distance_km)->multipliedBy($rate->rate->getAmount())->toScale(2, RoundingMode::HALF_UP);
        $line->forceFill(['amount' => (string) $amount, 'rate_snapshot' => $rate->rate, 'rate_version_id' => $rate->id])->save();
    }

    private function recalculate(Claim $c): BrickMoney
    {
        $total = $c->lines()->get()->reduce(fn (?BrickMoney $sum, ClaimLine $l) => $sum === null ? $l->amount : $sum->plus($l->amount)) ?? Money::zero();
        $c->forceFill(['total_amount' => $total])->save();

        return $total;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(Claim $claim, array $lines): void
    {
        $keep = [];
        $no = (int) $claim->lines()->max('line_no');
        foreach ($lines as $data) {
            $type = ClaimType::query()->findOrFail((int) $data['claim_type_id']);
            $attrs = [
                'claim_type_id' => $type->id, 'incurred_on' => Carbon::parse((string) $data['incurred_on'])->toDateString(),
                'description' => (string) $data['description'], 'category_id' => $data['category_id'] ?? $type->default_expense_category_id,
                'origin' => $data['origin'] ?? null, 'destination' => $data['destination'] ?? null,
                'distance_km' => isset($data['distance_km']) && $data['distance_km'] !== '' ? (string) $data['distance_km'] : null,
                'clinical_note' => $type->is_medical ? ($data['clinical_note'] ?? null) : null,
                'project_id' => $data['project_id'] ?? null,
            ];
            if ($type->code !== 'mileage') {
                $attrs['amount'] = Money::myr((string) $data['amount']);
            }
            /** @var ClaimLine|null $line */
            $line = isset($data['id']) ? $claim->lines()->whereKey((int) $data['id'])->first() : null;
            if ($line !== null) {
                $line->fill($attrs + ['status' => 'pending'])->save();
            } else {
                $line = $claim->lines()->create($attrs + ['line_no' => ++$no, 'amount' => $attrs['amount'] ?? '0.0100', 'status' => 'pending']);
            }
            if ($type->code === 'mileage') {
                $line->setRelation('claimType', $type);
                $this->priceMileage($line);
            }
            $keep[] = $line->id;
        }
        $claim->lines()->whereNotIn('id', $keep)->get()->each(function (ClaimLine $l): void {
            if ($this->documents->linkedTo('claim_line', $l->id) !== []) {
                throw new PostingRejected("Line {$l->line_no} has a receipt attached; remove the receipt link first.");
            }
            $l->delete();
        });
        $this->recalculate($claim);
    }

    private function transition(Claim $c, ClaimStatus $to, User $actor, ?string $reason = null): void
    {
        $from = $c->status;
        ClaimStatus::transitions()->assert('Claim', $from, $to);
        DB::table('claims')->where('id', $c->id)->update(['status' => $to->value, 'updated_at' => now()]);
        $c->setRawAttributes(array_merge($c->getAttributes(), ['status' => $to->value]), true);
        Audit::log('transition', $c, null, ['status' => $from->value], ['status' => $to->value], $reason, $actor);
    }
}
