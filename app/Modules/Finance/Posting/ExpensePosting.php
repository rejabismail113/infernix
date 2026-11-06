<?php

declare(strict_types=1);

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Enums\CapitalizationClass;
use App\Modules\Finance\Enums\PaymentSource;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\AccountResolver;

/**
 * Expense register entry → journal. Debit side by capitalisation class, credit side by who paid:
 *
 *   EXPENSE              Dr category expense account
 *   CONSUMABLE           Dr Consumables
 *   LOW_VALUE_EQUIPMENT  Dr Low-value equipment
 *   CAPITAL_ASSET        Dr Fixed Assets          (no expense line; M05 registers the asset)
 *   OWNER_DRAWING        Dr Owner Drawings        (equity; excluded from P&L)
 *   ─────────────────────────────────────────────
 *   DIRECT_COMPANY       Cr Bank (selected bank account)
 *   OWNER_PAID           Cr Owner Current Account (the business owes the owner — WF-03)
 *   STAFF_PAID_REIMBURSABLE  not allowed here: staff-paid costs go through Claims (single impact, UAT-02)
 *
 * @see SRS M01-02, M01-10, WF-03, docs/DECISIONS.md D-12
 */
final class ExpensePosting
{
    public function __construct(private readonly AccountResolver $accounts) {}

    public function draft(Transaction $t): JournalDraft
    {
        $class = $t->capitalization_class ?? throw new PostingRejected('Expense needs a capitalisation class.');
        $source = $t->payment_source ?? throw new PostingRejected('Expense needs a payment source.');
        if ($source === PaymentSource::StaffPaidReimbursable) {
            throw new PostingRejected('Staff-paid expenses are recorded as claims, not in the expense register.');
        }
        if ($class === CapitalizationClass::OwnerDrawing && $source !== PaymentSource::DirectCompany) {
            throw new PostingRejected('Owner drawings are business money paid for the owner (pay from a company bank account).');
        }

        $lines = [];
        $total = null;
        foreach ($t->lines as $line) {
            $account = match ($class) {
                CapitalizationClass::Expense => $line->account_id !== null ? ChartAccount::query()->findOrFail($line->account_id) : $this->accounts->forExpenseCategory($line->category_id),
                CapitalizationClass::Consumable => $this->accounts->system('consumables'),
                CapitalizationClass::LowValueEquipment => $this->accounts->system('low_value_equipment'),
                CapitalizationClass::CapitalAsset => $this->accounts->system('fixed_assets'),
                CapitalizationClass::OwnerDrawing => $this->accounts->system('owner_drawings'),
            };
            $lines[] = JournalLineDraft::debit($account->id, $line->amount, $line->description, $line->project_id ?? $t->project_id, $line->cost_centre_id, $line->activity_id ?? $t->activity_id);
            $total = $total === null ? $line->amount : $total->plus($line->amount);
        }
        if ($total === null) {
            throw new PostingRejected('Expense has no lines.');
        }
        $creditAccount = match ($source) {
            PaymentSource::DirectCompany => $this->accounts->forBank($t->bank_account_id ?? throw new PostingRejected('Choose the bank account that paid.')),
            PaymentSource::OwnerPaid => $this->accounts->system('owner_current'),
        };
        $lines[] = JournalLineDraft::credit($creditAccount->id, $total, $t->counterparty_name ?? $t->description);

        return new JournalDraft($t->transaction_date, 'transaction', $t->id, "Expense {$t->transaction_no}: ".($t->description ?? ''), $lines);
    }
}
