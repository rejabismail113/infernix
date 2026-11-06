<?php

declare(strict_types=1);

namespace App\Modules\Finance\Database\Seeders;

use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\Dictionary;
use App\Modules\Finance\Models\ChartAccount;
use Illuminate\Database\Seeder;

/**
 * Malaysian sole-proprietor starter chart of accounts. Every account is PLACEHOLDER pending accountant
 * approval (docs/DECISIONS.md D-11). system_key marks the accounts posting recipes use.
 *
 * @see SRS M01-06, §2.2
 */
final class ChartOfAccountsSeeder extends Seeder
{
    private const NOTE = 'PLACEHOLDER — pending accountant approval';

    /** code, name, type, subtype, system_key, postable, tax default */
    public const ACCOUNTS = [
        ['1000', 'Assets', 'asset', null, null, false, 'pending_review'],
        ['1010', 'Bank — Maybank current account', 'asset', 'bank', null, true, 'pending_review'],
        ['1020', 'Cash on hand', 'asset', 'cash', 'cash', true, 'pending_review'],
        ['1100', 'Accounts receivable', 'asset', 'receivable', 'accounts_receivable', true, 'pending_review'],
        ['1200', 'Fixed assets (at cost)', 'asset', 'fixed_asset', 'fixed_assets', true, 'capital'],
        ['1210', 'Accumulated depreciation', 'asset', 'accumulated_depreciation', 'accumulated_depreciation', true, 'capital'],
        ['1900', 'Suspense', 'asset', 'suspense', 'suspense', true, 'pending_review'],
        ['2000', 'Liabilities', 'liability', null, null, false, 'pending_review'],
        ['2010', 'Accounts payable', 'liability', 'payable', 'accounts_payable', true, 'pending_review'],
        ['2020', 'Staff claims payable', 'liability', 'claims_payable', 'staff_claims_payable', true, 'pending_review'],
        ['2030', 'Owner current account (owner-paid expenses)', 'liability', 'owner_current', 'owner_current', true, 'pending_review'],
        ['2040', 'Loans payable', 'liability', 'loan', 'loan_payable', true, 'pending_review'],
        ['2050', 'Refunds clearing', 'liability', 'refunds_clearing', 'refunds_clearing', true, 'pending_review'],
        ['2060', 'Unapplied receipts', 'liability', 'unapplied_receipts', 'unapplied_receipts', true, 'pending_review'],
        ['2070', 'SST payable (output tax)', 'liability', 'tax_payable', 'sst_payable', true, 'pending_review'],
        ['2080', 'Accrued staff & contractor payments', 'liability', 'accrued_payroll', 'accrued_staff_payments', true, 'pending_review'],
        ['3000', 'Equity', 'equity', null, null, false, 'pending_review'],
        ['3010', 'Owner capital', 'equity', 'owner_capital', 'owner_capital', true, 'non_deductible'],
        ['3020', 'Owner drawings', 'equity', 'owner_drawings', 'owner_drawings', true, 'non_deductible'],
        ['3900', 'Retained earnings', 'equity', 'retained_earnings', 'retained_earnings', true, 'pending_review'],
        ['4000', 'Income', 'income', null, null, false, 'pending_review'],
        ['4010', 'Project revenue', 'income', 'revenue', 'revenue_project', true, 'pending_review'],
        ['4020', 'Training revenue', 'income', 'revenue', 'revenue_training', true, 'pending_review'],
        ['4030', 'Grants', 'income', 'grant', 'grants', true, 'pending_review'],
        ['4090', 'Other income', 'income', 'other_income', 'other_income', true, 'pending_review'],
        ['5000', 'Expenses', 'expense', null, null, false, 'pending_review'],
        ['5010', 'Travel & mileage', 'expense', 'operating', null, true, 'pending_review'],
        ['5020', 'Meals & entertainment', 'expense', 'operating', null, true, 'pending_review'],
        ['5030', 'Accommodation', 'expense', 'operating', null, true, 'pending_review'],
        ['5040', 'Office supplies', 'expense', 'operating', null, true, 'pending_review'],
        ['5050', 'Software & subscriptions', 'expense', 'operating', null, true, 'pending_review'],
        ['5060', 'Professional fees', 'expense', 'operating', null, true, 'pending_review'],
        ['5070', 'Marketing', 'expense', 'operating', null, true, 'pending_review'],
        ['5080', 'Training & development', 'expense', 'operating', null, true, 'pending_review'],
        ['5090', 'Telephone & internet', 'expense', 'operating', null, true, 'pending_review'],
        ['5100', 'Utilities', 'expense', 'operating', null, true, 'pending_review'],
        ['5110', 'Rental', 'expense', 'operating', null, true, 'pending_review'],
        ['5120', 'Bank charges', 'expense', 'operating', null, true, 'pending_review'],
        ['5130', 'Staff medical', 'expense', 'operating', null, true, 'pending_review'],
        ['5140', 'CSR', 'expense', 'operating', null, true, 'pending_review'],
        ['5150', 'Low-value equipment', 'expense', 'low_value_equipment', 'low_value_equipment', true, 'pending_review'],
        ['5160', 'Consumables', 'expense', 'consumables', 'consumables', true, 'pending_review'],
        ['5170', 'Part-time & contractor fees', 'expense', 'staff_costs', 'staff_costs_contract', true, 'pending_review'],
        ['5800', 'Depreciation', 'expense', 'depreciation', 'depreciation_expense', true, 'pending_review'],
        ['5900', 'General expenses', 'expense', 'operating', 'general_expense', true, 'pending_review'],
    ];

    /** expense_category dictionary code → GL account code */
    public const CATEGORY_MAP = [
        'travel' => '5010', 'meals' => '5020', 'accommodation' => '5030', 'office' => '5040', 'software' => '5050', 'professional' => '5060',
        'marketing' => '5070', 'training' => '5080', 'telecom' => '5090', 'utilities' => '5100', 'rental' => '5110', 'bank_charges' => '5120',
        'medical' => '5130', 'csr' => '5140', 'equipment_low_value' => '5150', 'consumables' => '5160', 'other' => '5900',
    ];

    public function run(): void
    {
        $company = Company::current();
        $byCode = [];
        foreach (self::ACCOUNTS as [$code, $name, $type, $subtype, $key, $postable, $tax]) {
            $parent = $postable ? ($byCode[substr($code, 0, 1).'000'] ?? null) : null;
            $byCode[$code] = ChartAccount::withoutEvents(fn () => ChartAccount::query()->firstOrCreate(['company_id' => $company->id, 'code' => $code], [
                'name' => $name, 'type' => $type, 'subtype' => $subtype, 'system_key' => $key, 'parent_id' => $parent?->id,
                'is_postable' => $postable, 'tax_treatment_default' => $tax, 'active' => true, 'notes' => self::NOTE,
            ]));
        }
        $categories = Dictionary::query()->where('code', 'expense_category')->first()?->items()->get() ?? collect();
        foreach ($categories as $item) {
            if (isset(self::CATEGORY_MAP[$item->code])) {
                $item->forceFill(['meta' => array_merge($item->meta ?? [], ['gl_account_code' => self::CATEGORY_MAP[$item->code]])])->saveQuietly();
            }
        }
    }
}
