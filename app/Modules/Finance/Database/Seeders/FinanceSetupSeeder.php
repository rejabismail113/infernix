<?php

declare(strict_types=1);

namespace App\Modules\Finance\Database\Seeders;

use App\Kernel\Approval\ApprovalMatrix;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\CompanyBankAccount;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\BankImportProfile;
use App\Modules\Finance\Models\BankMatchRule;
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
            ['generic', 'Generic CSV (date, description, reference, amount)', null, ['date' => 0, 'description' => 1, 'reference' => 2, 'amount' => 3], 'd/m/Y', 0, 'Signed amount column; positive = money in.'],
            ['maybank', 'Maybank-style (date, description, debit, credit, balance)', 'Maybank', ['date' => 0, 'description' => 1, 'debit' => 2, 'credit' => 3, 'balance' => 4], 'd/m/Y', 0, 'PLACEHOLDER — confirm against a real Maybank2u business export.'],
            ['cimb', 'CIMB-style (date, description, reference, withdrawal, deposit, balance)', 'CIMB', ['date' => 0, 'description' => 1, 'reference' => 2, 'debit' => 3, 'credit' => 4, 'balance' => 5], 'd/m/Y', 0, 'PLACEHOLDER — confirm against a real CIMB BizChannel export.'],
        ] as [$key, $name, $bankName, $map, $fmt, $skip, $notes]) {
            $profile = BankImportProfile::query()->where('template_key', $key)->orWhere('name', $name)->first() ?? new BankImportProfile;
            $profile->forceFill(['template_key' => $key, 'name' => $name, 'bank_name' => $bankName, 'column_mapping' => $map, 'date_format' => $fmt, 'has_header' => true, 'skip_rows' => $skip, 'delimiter' => ',', 'notes' => $notes, 'active' => $profile->active ?? true])->save();
        }
        $owner = User::query()->whereHas('roles', fn ($r) => $r->where('code', 'OWN'))->orderBy('id')->first();
        if ($owner !== null && ! BankMatchRule::query()->exists()) {
            BankMatchRule::query()->create(['name' => 'Document number in bank reference', 'priority' => 10, 'direction' => 'any', 'date_window_days' => 7,
                'reference_pattern' => '/\\b((?:PV|RC|INV|BL|PB)-\\d{4}-\\d{4})\\b/i', 'active' => true, 'created_by' => $owner->id, 'notes' => 'PLACEHOLDER example rule: our document number quoted in the bank reference.']);
        }

        // Up to RM 5,000: one approver (FIN or OWN). Above: FIN or OWN, then OWN (a different person).
        foreach (['claim', 'bill', 'payment', 'journal', 'quotation', 'invoice', 'credit_note', 'payment_batch', 'vendor_po', 'purchase_request', 'asset_disposal'] as $type) {
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
