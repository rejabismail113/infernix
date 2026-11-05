<?php

declare(strict_types=1);

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Services\AccountResolver;
use Illuminate\Support\Carbon;

/**
 * Claim approved → Dr expense (per line category, project/cost centre kept) / Cr Staff Claims Payable.
 * This is the claim's ONE expense impact; the later payment only settles the payable (UAT-02).
 *
 * @see SRS M01-03, WF-01, UAT-02
 */
final class ClaimApprovalPosting
{
    public function __construct(private readonly AccountResolver $accounts) {}

    public function draft(Claim $claim, Carbon $date): JournalDraft
    {
        $lines = [];
        $total = null;
        foreach ($claim->lines as $line) {
            if ($line->status === 'rejected') {
                continue;
            }
            $category = $line->category_id ?? $line->claimType->default_expense_category_id;
            $account = $this->accounts->forExpenseCategory($category);
            $lines[] = JournalLineDraft::debit($account->id, $line->amount, "{$claim->claim_no} L{$line->line_no}: {$line->description}", $line->project_id ?? $claim->project_id, $claim->cost_centre_id);
            $total = $total === null ? $line->amount : $total->plus($line->amount);
        }
        if ($total === null) {
            throw new PostingRejected('No approved lines to post.');
        }
        $lines[] = JournalLineDraft::credit($this->accounts->system('staff_claims_payable')->id, $total, "{$claim->claim_no} payable to {$claim->staff->name}");

        return new JournalDraft($date, 'claim', $claim->id, "Claim {$claim->claim_no} approved: {$claim->purpose}", $lines);
    }
}
