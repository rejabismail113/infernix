<?php

declare(strict_types=1);

namespace App\Modules\Administration\Database\Seeders;

use App\Modules\Administration\Models\Company;
use Illuminate\Database\Seeder;

/** @see SRS G-03 */
final class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::withoutEvents(fn () => Company::query()->firstOrCreate(['name' => 'Infernix Evolv Hub'], [
            'ssm_no' => 'PLACEHOLDER-SSM',
            'address' => ['line1' => 'PLACEHOLDER address line 1', 'city' => 'Kuala Lumpur', 'postcode' => '50000', 'state' => 'Wilayah Persekutuan', 'country' => 'Malaysia'],
            'email' => 'hello@infernix.local',
            'signatory_name' => 'Julaiha',
            'signatory_title' => 'Owner',
        ]));
    }
}
