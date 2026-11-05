<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Finance\Enums\CapitalizationClass;
use App\Modules\Finance\Enums\InflowType;
use App\Modules\Finance\Enums\PaymentSource;
use App\Modules\Finance\Enums\TaxTreatment;
use App\Modules\Finance\Enums\TransactionStatus;
use App\Modules\Finance\Events\AssetQualifyingPurchaseRecorded;
use App\Modules\Finance\Events\ExpenseRecorded;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Posting\ExpensePosting;
use App\Modules\Finance\Posting\IncomePosting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Income and expense registers: draft → posted (journal via recipe) → reversed. Posted entries are never
 * edited; corrections are reversals (and, in a locked period, the reversal posts in an open period).
 *
 * @see SRS M01-01, M01-02, M01-09, M01-10, WF-03, UAT-09
 */
final class TransactionService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly PostingService $posting,
        private readonly ExpensePosting $expenseRecipe,
        private readonly IncomePosting $incomeRecipe,
    ) {}

    /**
     * @param  array{direction: string, inflow_type?: string|null, payment_source?: string|null, capitalization_class?: string|null, transaction_date: string, bank_account_id?: int|null, payment_method?: string|null, bank_reference?: string|null, counterparty_name?: string|null, vendor_id?: int|null, project_id?: int|null, description?: string|null, lines: list<array{description: string, amount: string, category_id?: int|null, cost_centre_id?: int|null, project_id?: int|null}>}  $data
     */
    public function create(array $data, User $actor): Transaction
    {
        $this->assertShape($data);

        return DB::transaction(function () use ($data, $actor): Transaction {
            $date = Carbon::parse($data['transaction_date']);
            $no = $this->numbering->allocate((int) $actor->company_id, 'TRANSACTION', (int) $date->format('Y'))['formatted'];
            $t = Transaction::query()->create([
                'company_id' => $actor->company_id, 'transaction_no' => $no, 'direction' => $data['direction'],
                'inflow_type' => $data['inflow_type'] ?? null, 'payment_source' => $data['payment_source'] ?? null,
                'capitalization_class' => $data['capitalization_class'] ?? null, 'transaction_date' => $date->toDateString(),
                'bank_account_id' => $data['bank_account_id'] ?? null, 'payment_method' => $data['payment_method'] ?? null,
                'bank_reference' => $data['bank_reference'] ?? null, 'counterparty_name' => $data['counterparty_name'] ?? null,
                'vendor_id' => $data['vendor_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'description' => $data['description'] ?? null,
                'tax_treatment' => $data['capitalization_class'] === CapitalizationClass::CapitalAsset->value ? TaxTreatment::Capital->value : TaxTreatment::PendingReview->value,
                'status' => TransactionStatus::Draft->value, 'gross_amount' => '0', 'created_by' => $actor->id,
            ]);
            $total = Money::zero();
            foreach (array_values($data['lines']) as $i => $line) {
                $amount = Money::myr($line['amount']);
                $t->lines()->create([
                    'line_no' => $i + 1, 'description' => $line['description'], 'amount' => $amount,
                    'category_id' => $line['category_id'] ?? null, 'cost_centre_id' => $line['cost_centre_id'] ?? null, 'project_id' => $line['project_id'] ?? null,
                ]);
                $total = $total->plus($amount);
            }
            $t->forceFill(['gross_amount' => $total])->save();

            return $t->refresh();
        });
    }

    public function post(Transaction $t, User $actor): Transaction
    {
        return DB::transaction(function () use ($t, $actor): Transaction {
            /** @var Transaction $locked */
            $locked = Transaction::query()->lockForUpdate()->with('lines')->findOrFail($t->id);
            TransactionStatus::transitions()->assert('Transaction', $locked->status, TransactionStatus::Posted);
            $draft = $locked->isInflow() ? $this->incomeRecipe->draft($locked) : $this->expenseRecipe->draft($locked);
            $journal = $this->posting->post($draft, $actor);
            $locked->forceFill(['status' => TransactionStatus::Posted, 'journal_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now()])->save();

            if (! $locked->isInflow()) {
                $assetLines = $locked->capitalization_class === CapitalizationClass::CapitalAsset ? $locked->lines : collect();
                DB::afterCommit(function () use ($locked, $assetLines): void {
                    Event::dispatch(new ExpenseRecorded($locked->id));
                    foreach ($assetLines as $line) {
                        Event::dispatch(new AssetQualifyingPurchaseRecorded('transaction', $locked->id, $line->id, $line->description, Money::toDb($line->amount), $locked->transaction_date->toDateString()));
                    }
                });
            }

            return $locked;
        });
    }

    public function reverse(Transaction $t, User $actor, string $reason): Transaction
    {
        return DB::transaction(function () use ($t, $actor, $reason): Transaction {
            /** @var Transaction $locked */
            $locked = Transaction::query()->lockForUpdate()->findOrFail($t->id);
            TransactionStatus::transitions()->assert('Transaction', $locked->status, TransactionStatus::Reversed);
            $reversal = $this->posting->reverse($locked->journal()->firstOrFail(), $actor, $reason);
            $locked->forceFill(['status' => TransactionStatus::Reversed, 'reversal_journal_id' => $reversal->id])->save();

            return $locked;
        });
    }

    public function cancel(Transaction $t, User $actor, string $reason): void
    {
        TransactionStatus::transitions()->assert('Transaction', $t->status, TransactionStatus::Cancelled);
        $t->forceFill(['status' => TransactionStatus::Cancelled])->save();
        Audit::log('cancel', $t, null, null, null, $reason, $actor);
    }

    /** Tax treatment is a review flag (FIN/OWN/AUD): it never changes the ledger, so it may be set after posting. */
    public function setTaxTreatment(Transaction $t, TaxTreatment $treatment, User $actor, ?string $note = null): void
    {
        $before = $t->tax_treatment->value;
        $t->forceFill(['tax_treatment' => $treatment])->save();
        Audit::log('tax_treatment', $t, null, ['tax_treatment' => $before], ['tax_treatment' => $treatment->value], $note, $actor);
    }

    /** @param  array<string, mixed>  $data */
    private function assertShape(array $data): void
    {
        if (($data['lines'] ?? []) === []) {
            throw new PostingRejected('Add at least one line.');
        }
        if ($data['direction'] === 'inflow') {
            if (InflowType::tryFrom((string) ($data['inflow_type'] ?? '')) === null) {
                throw new PostingRejected('Choose the type of money received.');
            }

            return;
        }
        $source = PaymentSource::tryFrom((string) ($data['payment_source'] ?? '')) ?? throw new PostingRejected('Choose who paid.');
        $class = CapitalizationClass::tryFrom((string) ($data['capitalization_class'] ?? '')) ?? throw new PostingRejected('Choose the expense classification.');
        if ($source === PaymentSource::StaffPaidReimbursable) {
            throw new PostingRejected('Staff-paid expenses must be submitted as a claim (so they are reimbursed and counted once).');
        }
        if ($class === CapitalizationClass::OwnerDrawing && $source !== PaymentSource::DirectCompany) {
            throw new PostingRejected('Owner drawings are personal items paid from a company bank account.');
        }
        if ($source === PaymentSource::DirectCompany && empty($data['bank_account_id'])) {
            throw new PostingRejected('Choose the bank account that paid.');
        }
    }
}
