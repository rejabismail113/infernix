<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Database\Seeders;

use App\Modules\DocumentCentre\Models\RequiredEvidenceRule;
use Illuminate\Database\Seeder;

/**
 * Required evidence rules. Rules for modules arriving later show as "pending module" in the report until
 * those modules register their entity types. @see SRS M06 outputs, M01-03, M01-09, §2.2
 */
final class RequiredEvidenceRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['company', 'always', 'ssm_registration', 'Company SSM registration certificate (validate identity before issuing production documents — SRS §2.2)'],
            ['claim_line', 'claim_type.receipt_required', 'receipt', 'Claim line requires a receipt when its claim type demands one'],
            ['expense', 'always', 'receipt', 'Expense requires a receipt or supplier invoice (tax evidence, M01-09)'],
            ['bill', 'always', 'invoice', 'Vendor bill requires the supplier invoice'],
            ['asset', 'always', 'receipt', 'Asset requires its purchase receipt (M05-05)'],
        ];
        foreach ($rules as [$type, $condition, $link, $description]) {
            RequiredEvidenceRule::withoutEvents(fn () => RequiredEvidenceRule::query()->firstOrCreate(
                ['entity_type' => $type, 'condition' => $condition, 'link_type' => $link],
                ['description' => $description, 'active' => true, 'notes' => 'PLACEHOLDER'],
            ));
        }
    }
}
