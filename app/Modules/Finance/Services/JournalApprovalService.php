<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Finance\Enums\JournalStatus;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Journal;
use Illuminate\Support\Facades\DB;

/**
 * Adjusting journals need approval by someone other than the preparer (matrix 'journal'); only then does
 * PostingService post them (allocating the JV number).
 *
 * @see SRS M01-08, WF-08 ("Adjust with approval"), UAT-11
 */
final class JournalApprovalService
{
    public function __construct(private readonly ApprovalService $approvals, private readonly PostingService $posting) {}

    public function approve(Journal $draft, User $approver, ?string $overrideReason = null): Journal
    {
        return DB::transaction(function () use ($draft, $approver, $overrideReason): Journal {
            /** @var Journal $j */
            $j = Journal::query()->lockForUpdate()->findOrFail($draft->id);
            if ($j->status !== JournalStatus::Draft) {
                throw new PostingRejected('Only draft journals can be approved.');
            }
            $amount = Money::fromDb((string) DB::table('journal_lines')->where('journal_id', $j->id)->sum('debit'));
            $prior = $this->approvals->priorApprovers('journal', $j->id);
            $decision = $this->approvals->authorize($approver, 'journal', $amount, count($prior) + 1, $j->created_by, $overrideReason, $prior);
            $this->approvals->record('journal', $j->id, $decision, $approver);
            if ($decision->ownerOverride) {
                $this->approvals->auditOverride($j, $approver, (string) $overrideReason);
            }
            Audit::log('approve', $j, null, null, ['level' => $decision->level, 'final' => $decision->finalLevel], $overrideReason, $approver);

            return $decision->finalLevel ? $this->posting->postDraft($j, $approver) : $j;
        });
    }
}
