<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Services;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\Contracts\IssuedDocumentRenderer;
use App\Kernel\Documents\IssuedDocumentRequest;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\Reports\TabularFileWriter;
use App\Modules\Finance\Services\LedgerGateway;
use App\Modules\HumanResources\Enums\TimesheetStatus;
use App\Modules\HumanResources\Exceptions\HrRejected;
use App\Modules\HumanResources\Models\PaymentBatch;
use App\Modules\HumanResources\Models\PaymentBatchLine;
use App\Modules\HumanResources\Models\Timesheet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Part-time payment batches (M04-04): calculated timesheets → batch → approval (matrix `payment_batch`,
 * maker-checker) → accrual posted (Dr Part-time & contractor fees per project / Cr Accrued staff payments) →
 * paid (Dr Accrued / Cr Bank) with a payment voucher or a payroll handoff CSV. Contractors need their invoice
 * attached before the batch can be submitted (M04-05).
 *
 * @see SRS M04-03, M04-04, M04-05, WF-05, UAT-11
 */
final class PaymentBatchService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ApprovalService $approvals,
        private readonly LedgerGateway $ledger,
        private readonly TimesheetService $timesheets,
        private readonly DocumentStore $documents,
        private readonly IssuedDocumentRenderer $renderer,
        private readonly TabularFileWriter $writer,
    ) {}

    /** @param  list<int>  $timesheetIds */
    public function create(array $timesheetIds, string $periodEnd, string $payoutMethod, User $actor): PaymentBatch
    {
        if ($timesheetIds === []) {
            throw new HrRejected('Choose at least one calculated timesheet.');
        }

        return DB::transaction(function () use ($timesheetIds, $periodEnd, $payoutMethod, $actor): PaymentBatch {
            $sheets = Timesheet::query()->lockForUpdate()->with('staff')->whereIn('id', $timesheetIds)->get();
            if ($sheets->count() !== count(array_unique($timesheetIds))) {
                throw new HrRejected('Some timesheets were not found.');
            }
            $batch = PaymentBatch::query()->create([
                'company_id' => $actor->company_id, 'batch_no' => $this->numbering->allocate((int) $actor->company_id, 'PAYMENT_BATCH', (int) Carbon::parse($periodEnd)->format('Y'))['formatted'],
                'period_end' => $periodEnd, 'status' => 'draft', 'payout_method' => $payoutMethod, 'created_by' => $actor->id,
            ]);
            $total = Money::zero();
            foreach ($sheets as $t) {
                if ($t->status !== TimesheetStatus::Calculated || $t->calculated_amount === null) {
                    throw new HrRejected("Timesheet #{$t->id} of {$t->staff->displayName()} is {$t->status->value}; only calculated timesheets can be batched.");
                }
                PaymentBatchLine::query()->create([
                    'payment_batch_id' => $batch->id, 'staff_profile_id' => $t->staff_profile_id, 'timesheet_id' => $t->id, 'amount' => $t->calculated_amount,
                    'classification' => $t->staff->classification, 'contractor_invoice_required' => $t->staff->contractorInvoiceRequired(),
                ]);
                $t->forceFill(['status' => TimesheetStatus::Batched])->save();
                $total = $total->plus($t->calculated_amount);
            }
            $batch->forceFill(['total_amount' => $total])->save();
            Audit::log('create', $batch, null, null, ['lines' => $sheets->count(), 'total' => Money::toDb($total)], null, $actor);

            return $batch->refresh();
        });
    }

    public function submit(PaymentBatch $batch, User $actor): PaymentBatch
    {
        return DB::transaction(function () use ($batch, $actor): PaymentBatch {
            /** @var PaymentBatch $b */
            $b = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($b->status !== 'draft') {
                throw new HrRejected('Only a draft batch can be submitted.');
            }
            foreach ($b->lines()->with('staff')->get() as $line) {
                if ($line->contractor_invoice_required && ! $this->documents->hasLinked('payment_batch_line', $line->id, 'invoice')) {
                    throw new HrRejected("{$line->staff->displayName()} is a contractor: attach their invoice before submitting (M04-05).");
                }
            }
            if ($this->approvals->levels('payment_batch', $b->total_amount) === []) {
                throw new HrRejected('No approval matrix is configured for payment batches.');
            }
            $b->forceFill(['status' => 'pending_approval', 'current_level' => 1])->save();
            Audit::log('transition', $b, null, ['status' => 'draft'], ['status' => 'pending_approval'], null, $actor);

            return $b;
        });
    }

    /** Maker-checker approval; the final level posts the accrual, split across the projects the time was booked to. */
    public function approve(PaymentBatch $batch, User $approver, ?string $overrideReason = null): PaymentBatch
    {
        return DB::transaction(function () use ($batch, $approver, $overrideReason): PaymentBatch {
            /** @var PaymentBatch $b */
            $b = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($b->status !== 'pending_approval') {
                throw new HrRejected('Only a batch pending approval can be approved.');
            }
            $decision = $this->approvals->authorize($approver, 'payment_batch', $b->total_amount, $b->current_level, $b->created_by, $overrideReason, $this->approvals->priorApprovers('payment_batch', $b->id));
            $this->approvals->record('payment_batch', $b->id, $decision, $approver);
            if ($decision->ownerOverride) {
                $this->approvals->auditOverride($b, $approver, (string) $overrideReason);
            }
            Audit::log('approve', $b, null, ['level' => $b->current_level], ['level' => $decision->level, 'final' => $decision->finalLevel], $overrideReason, $approver);
            if (! $decision->finalLevel) {
                $b->forceFill(['current_level' => $b->current_level + 1])->save();

                return $b;
            }
            $journalId = $this->ledger->postPayrollAccrual($b->id, $b->batch_no, $b->period_end, $this->accrualLines($b), $approver);
            $b->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'accrual_journal_id' => $journalId])->save();
            Timesheet::query()->whereIn('id', $b->lines()->pluck('timesheet_id'))->get()->each(fn (Timesheet $t) => $t->forceFill(['status' => TimesheetStatus::Approved])->save());

            return $b;
        });
    }

    /** Return a draft / pending batch: timesheets go back to "calculated" (their locked amounts are unchanged). */
    public function cancel(PaymentBatch $batch, User $actor, string $reason): PaymentBatch
    {
        return DB::transaction(function () use ($batch, $actor, $reason): PaymentBatch {
            /** @var PaymentBatch $b */
            $b = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($b->status, ['draft', 'pending_approval'], true)) {
                throw new HrRejected('Only a draft or pending batch can be cancelled.');
            }
            Timesheet::query()->whereIn('id', $b->lines()->pluck('timesheet_id'))->get()->each(fn (Timesheet $t) => $t->forceFill(['status' => TimesheetStatus::Calculated])->save());
            $this->approvals->reset('payment_batch', $b->id);
            $b->forceFill(['status' => 'cancelled'])->save();
            Audit::log('cancel', $b, null, null, null, $reason, $actor);

            return $b;
        });
    }

    /** Pay out: post Dr Accrued / Cr Bank, then (after commit) the voucher or the payroll handoff file. */
    public function markPaid(PaymentBatch $batch, int $bankAccountId, string $paidOn, User $actor): PaymentBatch
    {
        $paid = DB::transaction(function () use ($batch, $bankAccountId, $paidOn, $actor): PaymentBatch {
            /** @var PaymentBatch $b */
            $b = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($b->status !== 'approved') {
                throw new HrRejected('Only an approved batch can be paid.');
            }
            $date = Carbon::parse($paidOn);
            $journalId = $this->ledger->postPayrollPayout($b->id, $b->batch_no, $date, $bankAccountId, (string) $b->total_amount->getAmount(), $actor);
            $voucherNo = $b->payout_method === 'voucher' ? $this->numbering->allocate((int) $b->company_id, 'PAYMENT_VOUCHER', (int) $date->format('Y'))['formatted'] : null;
            $b->forceFill(['status' => 'paid', 'paid_on' => $date->toDateString(), 'bank_account_id' => $bankAccountId, 'payout_journal_id' => $journalId, 'voucher_no' => $voucherNo])->save();
            Timesheet::query()->whereIn('id', $b->lines()->pluck('timesheet_id'))->get()->each(fn (Timesheet $t) => $t->forceFill(['status' => TimesheetStatus::Paid])->save());
            Audit::log('pay', $b, null, ['status' => 'approved'], ['status' => 'paid', 'voucher_no' => $voucherNo], null, $actor);

            return $b;
        });
        $paid->payout_method === 'voucher' ? $this->generateVoucher($paid, $actor) : $this->generateHandoff($paid, $actor);

        return $paid->refresh();
    }

    public function generateVoucher(PaymentBatch $b, User $actor): ?int
    {
        try {
            $b->loadMissing('lines.staff');
            $bank = collect($this->ledger->bankAccountOptions())->firstWhere('id', $b->bank_account_id);
            $id = $this->renderer->issue(new IssuedDocumentRequest(
                type: 'payment_voucher', documentNo: (string) $b->voucher_no, sourceType: 'payment_batch', sourceId: $b->id, counterpartyType: 'payee',
                counterparty: ['name' => 'Part-time staff & contractors ('.$b->lines->count().')'],
                lines: $b->lines->map(fn (PaymentBatchLine $l): array => ['ref' => $l->staff->staff_no, 'description' => $l->staff->displayName().' — timesheet #'.$l->timesheet_id.($l->contractor_invoice_required ? ' (contractor invoice on file)' : ''), 'amount' => (string) $l->amount->getAmount()])->values()->all(),
                total: (string) $b->total_amount->getAmount(), issueDate: $b->paid_on ?? today(), link: new LinkTarget('payment_batch', $b->id, 'issued_document'), actor: $actor,
                extra: ['method' => 'BATCH', 'reference' => $b->batch_no, 'bank' => $bank['label'] ?? null, 'prepared_by' => User::query()->find($b->created_by)?->name, 'approved_by' => User::query()->find($b->approved_by)?->name, 'owner_override' => false],
            ));
            $b->forceFill(['voucher_document_id' => $id])->save();

            return $id;
        } catch (Throwable $e) {
            Log::error('Batch voucher generation failed', ['batch_id' => $b->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Payroll handoff. The copy kept in the Document Centre is MASKED (a record of what was paid); the file with
     * full account numbers is produced only on demand by handoffRows() for users allowed to reveal staff bank
     * details, and every such download is audited (M04-04, M04-06).
     */
    public function generateHandoff(PaymentBatch $b, User $actor): int
    {
        $path = $this->writer->write('csv', self::HANDOFF_HEADERS, $this->handoffRows($b, false));
        try {
            $stored = $this->documents->attach(new UploadedFile($path, "payroll-handoff-{$b->batch_no}-masked.csv", 'text/csv', null, true), $actor,
                ['title' => "Payroll handoff {$b->batch_no} (masked)", 'classification' => 'restricted', 'retention_rule' => 'finance_7y'], new LinkTarget('payment_batch', $b->id, 'evidence'));
        } finally {
            @unlink($path);
        }
        $b->forceFill(['handoff_document_id' => $stored->documentId])->save();

        return $stored->documentId;
    }

    public const HANDOFF_HEADERS = ['Staff no', 'Legal name', 'Classification', 'Amount (MYR)', 'Bank', 'Account no', 'Account name'];

    /** @return list<list<string|null>> */
    public function handoffRows(PaymentBatch $b, bool $full): array
    {
        $b->loadMissing('lines.staff.bank');
        $rows = [];
        foreach ($b->lines as $l) {
            $bank = $l->staff->bank;
            $rows[] = [$l->staff->staff_no, $l->staff->legal_name, $l->classification, (string) $l->amount->getAmount(), $bank?->bank_name,
                $bank === null ? null : ($full ? $bank->account_no : $bank->masked()), $bank?->account_name];
        }

        return $rows;
    }

    /**
     * Split each line's amount across the projects its timesheet booked time to (pro rata by quantity; rounding
     * residue on the last project), so part-time costs count in project margin.
     *
     * @return list<array{project_id: int|null, amount: string, description: string}>
     */
    private function accrualLines(PaymentBatch $b): array
    {
        $out = [];
        foreach ($b->lines()->with(['staff', 'timesheet'])->get() as $line) {
            $byProject = $this->timesheets->quantityByProject($line->timesheet);
            $totalQty = array_reduce($byProject, fn (BigDecimal $c, string $q) => $c->plus($q), BigDecimal::zero());
            $remaining = $line->amount;
            $keys = array_keys($byProject);
            foreach ($keys as $i => $projectId) {
                $share = $i === count($keys) - 1 || $totalQty->isZero()
                    ? $remaining
                    : BrickMoney::of($line->amount->getAmount()->multipliedBy($byProject[$projectId])->dividedBy($totalQty, 2, RoundingMode::HALF_UP), 'MYR');
                $remaining = $remaining->minus($share);
                $out[] = ['project_id' => (int) $projectId, 'amount' => (string) $share->getAmount(), 'description' => "{$b->batch_no} {$line->staff->displayName()} ts#{$line->timesheet_id}"];
            }
            if ($keys === []) {
                $out[] = ['project_id' => null, 'amount' => (string) $line->amount->getAmount(), 'description' => "{$b->batch_no} {$line->staff->displayName()}"];
            }
        }

        return $out;
    }
}
