<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Finance\Enums\InflowType;
use App\Modules\Finance\Exceptions\PostingRejected;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\ChartAccount;

/**
 * Maps business concepts to GL accounts. System accounts are found by `system_key`; expense categories by
 * `dictionary_items.meta.gl_account_code`. All mappings are seeded as PLACEHOLDER pending accountant review.
 *
 * @see SRS M01-06, M01-01, M01-10, docs/DECISIONS.md D-11, D-12
 */
final class AccountResolver
{
    /** @var array<string, ChartAccount> */
    private array $byKey = [];

    public function system(string $key): ChartAccount
    {
        return $this->byKey[$key] ??= ChartAccount::query()->where('system_key', $key)->where('active', true)->first()
            ?? throw new PostingRejected("No active GL account is mapped to [{$key}]; configure the chart of accounts.");
    }

    public function forInflow(InflowType $type): ChartAccount
    {
        return $this->system(match ($type) {
            InflowType::SalesProject => 'revenue_project',
            InflowType::SalesTraining => 'revenue_training',
            InflowType::Grant => 'grants',
            InflowType::OtherIncome => 'other_income',
            InflowType::CapitalInjection => 'owner_capital',
            InflowType::Loan => 'loan_payable',
            InflowType::Refund => 'refunds_clearing',
        });
    }

    public function forExpenseCategory(?int $categoryId): ChartAccount
    {
        if ($categoryId === null) {
            return $this->system('general_expense');
        }
        $item = DictionaryItem::query()->find($categoryId);
        $code = $item?->meta['gl_account_code'] ?? null;
        if (! is_string($code)) {
            return $this->system('general_expense');
        }

        return ChartAccount::query()->where('code', $code)->where('active', true)->first() ?? $this->system('general_expense');
    }

    public function forBank(int $bankAccountId): ChartAccount
    {
        $bank = BankAccount::query()->with('glAccount')->find($bankAccountId)
            ?? throw new PostingRejected('Unknown bank account.');

        return $bank->glAccount;
    }
}
