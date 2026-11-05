<?php

declare(strict_types=1);

namespace App\Modules\Finance\Database\Seeders;

use App\Kernel\Approval\ApprovalMatrix;
use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\CompanyBankAccount;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\ChartAccount;
use Illuminate\Database\Seeder;

/**
 * Default bank account, CSV import profiles and approval matrices (all PLACEHOLDER, D-15).
 *
 * @see SRS M01-07, M12-03, §2.2
 */
final class FinanceSetupSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::current();
        $cba = CompanyBankAccount::withoutEvents(fn () => CompanyBankAccount::query()->firstOrCreate(['company_id' => $company->id, 'bank_name' => 'Maybank'], [
            'account_no' => '5140PLACEHOLDER01', 'account_name' => $company->name, 'is_default' => true, 'active' => true,
        ]));
        $gl = ChartAccount::query()->where('code', '1010')->firstOrFail();
        BankAccount::withoutEvents(fn () => BankAccount::query()->firstOrCreate(['company_bank_account_id' => $cba->id], ['gl_account_id' => $gl->id, 'opening_balance' => '0', 'opening_balance_date' => '2026-01-01']));
        $cba->forceFill(['gl_account_id' => $gl->id])->saveQuietly();

        foreach ([
            ['Generic CSV (date, description, reference, amount)', ['date' => 0, 'description' => 1, 'reference' => 2, 'amount' => 3], 'd/m/Y', 'Signed amount column; positive = money in.'],
            ['Maybank-style (date, description, debit, credit, balance)', ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'balance' => 4], 'd/m/Y', 'PLACEHOLDER — confirm against a real Maybank export.'],
        ] as [$name, $map, $fmt, $notes]) {
            BankImportProfile::query()->firstOrCreate(['name' => $name], ['column_mapping' => $map, 'date_format' => $fmt, 'has_header' => true, 'notes' => $notes, 'active' => true]);
        }

        // Up to RM 5,000: one approver (FIN or OWN). Above: FIN or OWN, then OWN (a different person).
        foreach (['claim', 'bill', 'payment', 'journal', 'invoice', 'credit_note', 'purchase_request', 'asset_disposal'] as $type) {
            if (ApprovalMatrix::query()->where('entity_type', $type)->exists()) {
                continue;
            }
            $m = ApprovalMatrix::withoutEvents(fn () => ApprovalMatrix::query()->create(['entity_type' => $type, 'version' => 1, 'effective_from' => '2026-01-01', 'notes' => 'PLACEHOLDER thresholds pending owner sign-off (D-15)']));
            $m->rules()->createMany([
                ['min_amount' => '0', 'max_amount' => '5000', 'level' => 1, 'approver_roles' => ['FIN', 'OWN']],
                ['min_amount' => '5000.01', 'max_amount' => null, 'level' => 1, 'approver_roles' => ['FIN', 'OWN']],
                ['min_amount' => '5000.01', 'max_amount' => null, 'level' => 2, 'approver_roles' => ['OWN']],
            ]);
        }
    }
}
