<?php

declare(strict_types=1);

namespace App\Modules\Administration\Database\Seeders;

use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\FiscalYear;
use App\Modules\Administration\Services\FiscalPeriodService;
use Illuminate\Database\Seeder;

/** Calendar fiscal year (docs/DECISIONS.md D-24). @see SRS G-03 */
final class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::current();
        foreach ([2026, 2027] as $year) {
            if (! FiscalYear::query()->where('company_id', $company->id)->where('year', $year)->exists()) {
                FiscalYear::withoutEvents(fn () => app(FiscalPeriodService::class)->createYear($company->id, $year));
            }
        }
    }
}
