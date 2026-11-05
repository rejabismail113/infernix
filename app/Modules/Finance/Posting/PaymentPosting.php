<?php

declare(strict_types=1);

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\AccountResolver;

/**
 * Payment → Dr Accounts Payable (bill allocations) / Dr Staff Claims Payable (claim allocations),
 * Cr Bank. Settles payables only — never a second expense (UAT-02).
 *
 * @see SRS M01-05, M03-06
 */
final class PaymentPosting
{
    public function __construct(private readonly AccountResolver $accounts) {}

    public function draft(Payment $payment): JournalDraft
    {
        $lines = [];
        $total = null;
        foreach ($payment->allocations as $a) {
            $account = match ($a->target_type) {
                'bill' => $this->accounts->system('accounts_payable'),
                'claim' => $this->accounts->system('staff_claims_payable'),
                default => throw new PostingRejected("Unsupported allocation target {$a->target_type}."),
            };
            $lines[] = JournalLineDraft::debit($account->id, $a->amount, "{$payment->payment_no} → {$a->target_type} #{$a->target_id}");
            $total = $total === null ? $a->amount : $total->plus($a->amount);
        }
        if ($total === null || ! $total->isEqualTo($payment->amount)) {
            throw new PostingRejected('Payment amount must equal the sum of its allocations.');
        }
        $lines[] = JournalLineDraft::credit($this->accounts->forBank($payment->bank_account_id)->id, $payment->amount, "{$payment->payment_no} {$payment->payee_name} {$payment->reference}");

        return new JournalDraft($payment->payment_date, 'payment', $payment->id, "Payment {$payment->payment_no} to {$payment->payee_name}", $lines);
    }
}
