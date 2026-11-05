<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Database\Seeders;

use App\Modules\DocumentCentre\Models\RetentionRule;
use Illuminate\Database\Seeder;

/** PLACEHOLDER retention periods pending accountant / PDPA review (docs/DECISIONS.md D-29). @see SRS M06-05 */
final class RetentionRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['general_7y', 'General business record (7 years)', 84, 'upload'],
            ['finance_7y', 'Finance evidence (7 years after year end)', 84, 'upload'],
            ['hr_7y', 'HR record (7 years after engagement end)', 84, 'entity_closed'],
            ['csr_3y', 'CSR photos & evidence (3 years)', 36, 'upload'],
            ['short_1y', 'Working file (1 year)', 12, 'upload'],
        ];
        foreach ($rules as [$code, $name, $months, $basis]) {
            RetentionRule::withoutEvents(fn () => RetentionRule::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'retain_months' => $months, 'basis' => $basis, 'active' => true,
                'notes' => 'PLACEHOLDER — confirm with accountant / PDPA review',
            ]));
        }
    }
}
