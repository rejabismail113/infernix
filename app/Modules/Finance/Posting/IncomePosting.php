<?php

declare(strict_types=1);

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\AccountResolver;

/**
 * Direct inflow (no invoice): Dr Bank / Cr revenue, grant or other income — or, for capital injections,
 * loans and refunds, Cr a BALANCE-SHEET account (never revenue, M01-01). Invoiced sales are received via
 * M03 receipts (Dr Bank / Cr AR) in Prompt 3.
 *
 * @see SRS M01-01
 */
final class IncomePosting
{
    public function __construct(private readonly AccountResolver $accounts) {}

    public function draft(Transaction $t): JournalDraft
    {
        $type = $t->inflow_type ?? throw new PostingRejected('Income needs an inflow type.');
        $bank = $this->accounts->forBank($t->bank_account_id ?? throw new PostingRejected('Choose the bank account that received the money.'));
        $credit = $this->accounts->forInflow($type);

        $lines = [];
        $total = null;
        foreach ($t->lines as $line) {
            $lines[] = JournalLineDraft::credit($line->account_id ?? $credit->id, $line->amount, $line->description, $line->project_id ?? $t->project_id, $line->cost_centre_id);
            $total = $total === null ? $line->amount : $total->plus($line->amount);
        }
        if ($total === null) {
            throw new PostingRejected('Income has no lines.');
        }
        array_unshift($lines, JournalLineDraft::debit($bank->id, $total, $t->counterparty_name ?? $t->description));

        return new JournalDraft($t->transaction_date, 'transaction', $t->id, "Income {$t->transaction_no}: ".($t->description ?? ''), $lines);
    }
}
