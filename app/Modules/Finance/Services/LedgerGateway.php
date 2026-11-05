<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\ChartAccount;
use App\Modules\Finance\Models\Journal;
use App\Modules\Finance\Posting\JournalDraft;
use App\Modules\Finance\Posting\JournalLineDraft;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;

/**
 * The typed posting API M01 offers other modules (CLAUDE.md: "other modules … call typed posting recipes").
 * Inputs are IDs, dates and decimal strings only — Finance never depends on the caller's models — and every
 * journal still goes through PostingService (balance, open period, numbering, audit).
 *
 * @see SRS M01-06, M03-03, M03-04, M03-07, WF-04
 */
final class LedgerGateway
{
    public function __construct(private readonly PostingService $posting, private readonly AccountResolver $accounts) {}

    /**
     * Invoice issued (accrual): Dr Accounts Receivable (total) / Cr revenue per line account (net) / Cr SST payable (tax).
     *
     * @param  list<array{revenue_account_id: int|null, net: string, tax: string, description: string}>  $lines
     */
    public function postInvoice(int $invoiceId, string $documentNo, string $clientName, Carbon $date, array $lines, User $actor, ?int $projectId = null): int
    {
        return $this->postSales('invoice', $invoiceId, "Invoice {$documentNo} ({$clientName})", $date, $lines, $actor, $projectId, false);
    }

    /**
     * Credit note issued: the mirror of the invoice lines it credits — Dr revenue (net) / Dr SST (tax) / Cr AR.
     *
     * @param  list<array{revenue_account_id: int|null, net: string, tax: string, description: string}>  $lines
     */
    public function postCreditNote(int $creditNoteId, string $documentNo, string $invoiceNo, Carbon $date, array $lines, User $actor, ?int $projectId = null): int
    {
        return $this->postSales('credit_note', $creditNoteId, "Credit note {$documentNo} against {$invoiceNo}", $date, $lines, $actor, $projectId, true);
    }

    /**
     * Cash actually received: Dr Bank (amount) / Cr AR (allocated to invoices) / Cr Unapplied receipts (rest).
     */
    public function postReceipt(int $receiptId, string $receiptNo, string $payer, Carbon $date, int $bankAccountId, string $amount, string $allocated, User $actor): int
    {
        $total = Money::myr($amount);
        $applied = Money::myr($allocated);
        if (! $total->isPositive() || $applied->isNegative() || $applied->isGreaterThan($total)) {
            throw new PostingRejected('Receipt allocations must be between zero and the amount received.');
        }
        $lines = [JournalLineDraft::debit($this->accounts->forBank($bankAccountId)->id, $total, "{$receiptNo} {$payer}")];
        if ($applied->isPositive()) {
            $lines[] = JournalLineDraft::credit($this->accounts->system('accounts_receivable')->id, $applied, "{$receiptNo} applied to invoices");
        }
        $unapplied = $total->minus($applied);
        if ($unapplied->isPositive()) {
            $lines[] = JournalLineDraft::credit($this->accounts->system('unapplied_receipts')->id, $unapplied, "{$receiptNo} unapplied");
        }

        return $this->posting->post(new JournalDraft($date, 'receipt', $receiptId, "Receipt {$receiptNo} from {$payer}", $lines), $actor)->id;
    }

    /** Reverse a journal this gateway posted (void invoice / credit note / receipt). Returns the reversal id. */
    public function reverse(int $journalId, User $actor, string $reason): int
    {
        return $this->posting->reverse(Journal::query()->findOrFail($journalId), $actor, $reason)->id;
    }

    public function journalNo(?int $journalId): ?string
    {
        return $journalId === null ? null : Journal::query()->whereKey($journalId)->value('journal_no');
    }

    /** @return list<array{id: int, label: string}> */
    public function bankAccountOptions(): array
    {
        return BankAccount::query()->with('companyAccount')->get()->map(fn (BankAccount $b): array => ['id' => $b->id, 'label' => $b->label()])->values()->all();
    }

    /** @return list<array{id: int, label: string}> */
    public function revenueAccountOptions(): array
    {
        return ChartAccount::query()->where('type', 'income')->where('postable', true)->where('active', true)->orderBy('code')->get()
            ->map(fn (ChartAccount $a): array => ['id' => $a->id, 'label' => "{$a->code} {$a->name}"])->values()->all();
    }

    public function defaultRevenueAccountId(string $engagementType = 'project'): int
    {
        return $this->accounts->system($engagementType === 'training' ? 'revenue_training' : 'revenue_project')->id;
    }

    /** @param  list<array{revenue_account_id: int|null, net: string, tax: string, description: string}>  $lines */
    private function postSales(string $sourceType, int $sourceId, string $memo, Carbon $date, array $lines, User $actor, ?int $projectId, bool $credit): int
    {
        $byAccount = [];
        $tax = Money::zero();
        foreach ($lines as $l) {
            $accountId = $l['revenue_account_id'] ?? $this->defaultRevenueAccountId();
            $account = ChartAccount::query()->whereKey($accountId)->where('type', 'income')->where('active', true)->first()
                ?? throw new PostingRejected('Each line must post to an active income account.');
            $byAccount[$account->id] = ($byAccount[$account->id] ?? Money::zero())->plus(Money::myr($l['net']));
            $tax = $tax->plus(Money::myr($l['tax']));
        }
        $journalLines = [];
        $total = Money::zero();
        foreach ($byAccount as $accountId => $net) {
            if ($net->isPositive()) {
                $journalLines[] = $this->side($credit, $accountId, $net, $memo, $projectId);
                $total = $total->plus($net);
            }
        }
        if ($tax->isPositive()) {
            $journalLines[] = $this->side($credit, $this->accounts->system('sst_payable')->id, $tax, 'Output tax', $projectId);
            $total = $total->plus($tax);
        }
        if (! $total->isPositive()) {
            throw new PostingRejected('A document with a zero total cannot be posted.');
        }
        $journalLines[] = $this->side(! $credit, $this->accounts->system('accounts_receivable')->id, $total, $memo, $projectId);

        return $this->posting->post(new JournalDraft($date, $sourceType, $sourceId, $memo, $journalLines), $actor)->id;
    }

    /** Invoice: revenue/tax side($credit=false) → credit, AR side(true) → debit. Credit note: the mirror. */
    private function side(bool $debit, int $accountId, BrickMoney $amount, string $description, ?int $projectId): JournalLineDraft
    {
        return $debit
            ? JournalLineDraft::debit($accountId, $amount, $description, $projectId)
            : JournalLineDraft::credit($accountId, $amount, $description, $projectId);
    }
}
