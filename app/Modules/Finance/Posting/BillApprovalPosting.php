<?php

declare(strict_types=1);

namespace App\Modules\Finance\Posting;

use App\Modules\Finance\Enums\CapitalizationClass;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Services\AccountResolver;
use Illuminate\Support\Carbon;

/**
 * Bill approved (accrual) → Dr expense / consumables / low-value equipment / Fixed Assets per line,
 * Cr Accounts Payable. Capital lines emit AssetQualifyingPurchaseRecorded (in BillService).
 *
 * @see SRS M01-05, M01-10, WF-02
 */
final class BillApprovalPosting
{
    public function __construct(private readonly AccountResolver $accounts) {}

    public function draft(Bill $bill, Carbon $date): JournalDraft
    {
        $lines = [];
        foreach ($bill->lines as $line) {
            $account = match ($line->capitalization_class) {
                CapitalizationClass::CapitalAsset => $this->accounts->system('fixed_assets'),
                CapitalizationClass::LowValueEquipment => $this->accounts->system('low_value_equipment'),
                CapitalizationClass::Consumable => $this->accounts->system('consumables'),
                default => $this->accounts->forExpenseCategory($line->category_id),
            };
            $lines[] = JournalLineDraft::debit($account->id, $line->amount, "{$bill->bill_no}: {$line->description}", $line->project_id);
        }
        if ($lines === []) {
            throw new PostingRejected('Bill has no lines.');
        }
        $lines[] = JournalLineDraft::credit($this->accounts->system('accounts_payable')->id, $bill->total_amount, "{$bill->vendor->name} {$bill->vendor_invoice_no}");

        return new JournalDraft($date, 'bill', $bill->id, "Bill {$bill->bill_no} ({$bill->vendor->name} {$bill->vendor_invoice_no})", $lines);
    }
}
