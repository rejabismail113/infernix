<?php

declare(strict_types=1);

namespace App\Modules\Administration\Database\Seeders;

use App\Modules\Administration\Models\ClaimType;
use App\Modules\Administration\Models\ClaimTypeRate;
use App\Modules\Administration\Models\Dictionary;
use App\Modules\Administration\Models\DictionaryItem;
use Illuminate\Database\Seeder;

/**
 * PLACEHOLDER dictionary values pending owner/accountant confirmation (docs/DECISIONS.md D-14, D-15).
 *
 * @see SRS G-09, M01-04, §2.2
 */
final class DictionarySeeder extends Seeder
{
    private const NOTE = 'PLACEHOLDER — confirm with owner/accountant before production';

    public function run(): void
    {
        $dicts = [
            'expense_category' => ['Expense categories', [
                'travel' => 'Travel & mileage', 'meals' => 'Meals & entertainment', 'accommodation' => 'Accommodation', 'office' => 'Office supplies',
                'software' => 'Software & subscriptions', 'professional' => 'Professional fees', 'marketing' => 'Marketing', 'training' => 'Training & development',
                'telecom' => 'Telephone & internet', 'utilities' => 'Utilities', 'rental' => 'Rental', 'bank_charges' => 'Bank charges', 'medical' => 'Staff medical',
                'equipment_low_value' => 'Low-value equipment', 'consumables' => 'Consumables', 'csr' => 'CSR', 'other' => 'Other',
            ]],
            'income_category' => ['Income categories', ['project' => 'Project revenue', 'training' => 'Training revenue', 'grant' => 'Grants', 'other' => 'Other income', 'capital' => 'Capital injection (balance sheet)', 'loan' => 'Loan received (balance sheet)', 'refund' => 'Refund received (balance sheet)']],
            'project_type' => ['Project types', ['consulting' => 'Consulting', 'training' => 'Training', 'retainer' => 'Retainer', 'development' => 'Development', 'event' => 'Event']],
            'tax_code' => ['Tax codes', ['NONE' => 'No tax', 'SST-8' => 'SST 8% (inactive placeholder)', 'SST-6' => 'SST 6% (inactive placeholder)']],
            'cost_centre' => ['Cost centres', ['general' => 'General & admin', 'delivery' => 'Delivery', 'sales' => 'Sales & marketing', 'csr' => 'CSR']],
            'status_label' => ['Status labels', ['draft' => 'Draft', 'submitted' => 'Submitted', 'pending_approval' => 'Pending approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'paid' => 'Paid', 'posted' => 'Posted', 'archived' => 'Archived']],
            'activity_type' => ['Activity types', ['event' => 'Event', 'site_visit' => 'Site visit', 'conference' => 'Conference', 'trip' => 'Trip', 'training_delivery' => 'Training delivery']],
            'asset_category' => ['Asset categories', ['computer' => 'Computers & laptops', 'furniture' => 'Furniture', 'equipment' => 'Equipment', 'vehicle' => 'Vehicle', 'software' => 'Software licence']],
            'credential_category' => ['Credential categories', ['cloud' => 'Cloud', 'bi' => 'BI', 'database' => 'Database', 'development' => 'Development', 'ai' => 'AI', 'corporate' => 'Corporate', 'client' => 'Client', 'api' => 'API']],
        ];

        foreach ($dicts as $code => [$name, $items]) {
            $dict = Dictionary::withoutEvents(fn () => Dictionary::query()->firstOrCreate(['code' => $code], ['name' => $name, 'system' => true]));
            $i = 0;
            foreach ($items as $itemCode => $label) {
                $meta = match (true) {
                    $code === 'tax_code' && $itemCode === 'SST-8' => ['rate' => '8.00', 'active_default' => false],
                    $code === 'tax_code' && $itemCode === 'SST-6' => ['rate' => '6.00', 'active_default' => false],
                    $code === 'tax_code' => ['rate' => '0.00'],
                    $code === 'income_category' && in_array($itemCode, ['capital', 'loan', 'refund'], true) => ['balance_sheet' => true],
                    default => null,
                };
                DictionaryItem::withoutEvents(fn () => DictionaryItem::query()->firstOrCreate(
                    ['dictionary_id' => $dict->id, 'code' => $itemCode, 'effective_from' => '2026-01-01'],
                    ['label_en' => $label, 'meta' => $meta, 'sort_order' => $i++, 'notes' => self::NOTE, 'active' => ! str_starts_with($itemCode, 'SST')],
                ));
            }
        }

        $travel = DictionaryItem::query()->whereRelation('dictionary', 'code', 'expense_category')->where('code', 'travel')->first();
        $medical = DictionaryItem::query()->whereRelation('dictionary', 'code', 'expense_category')->where('code', 'medical')->first();
        $types = [
            ['mileage', 'Mileage', false, false, $travel?->id, null, '0.6000', 'km'],
            ['per_diem', 'Per diem', false, false, $travel?->id, null, '50.0000', 'day'],
            ['medical', 'Medical', true, true, $medical?->id, '500.0000', null, null],
            ['meals', 'Meals', true, false, null, null, null, null],
            ['accommodation', 'Accommodation', true, false, $travel?->id, null, null, null],
            ['transport', 'Transport (taxi/e-hailing/toll/parking)', true, false, $travel?->id, null, null, null],
            ['other', 'Other', true, false, null, null, null, null],
        ];
        foreach ($types as [$code, $name, $receipt, $isMedical, $catId, $limit, $rate, $unit]) {
            $ct = ClaimType::withoutEvents(fn () => ClaimType::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'receipt_required' => $receipt, 'is_medical' => $isMedical, 'default_expense_category_id' => $catId,
                'per_claim_limit' => $limit, 'notes' => self::NOTE, 'active' => true,
            ]));
            if ($rate !== null && ! $ct->rates()->exists()) {
                ClaimTypeRate::withoutEvents(fn () => $ct->rates()->create(['rate' => $rate, 'unit' => $unit, 'effective_from' => '2026-01-01', 'version' => 1, 'notes' => self::NOTE]));
            }
        }
    }
}
