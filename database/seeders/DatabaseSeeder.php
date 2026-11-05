<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Database\Seeders\CompanySeeder;
use App\Modules\Administration\Database\Seeders\DictionarySeeder;
use App\Modules\Administration\Database\Seeders\FiscalYearSeeder;
use App\Modules\Administration\Database\Seeders\NotificationRuleSeeder;
use App\Modules\Administration\Database\Seeders\NumberingSequenceSeeder;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            RoleSeeder::class,
            PermissionMatrixSeeder::class,
            OwnerUserSeeder::class,
            FiscalYearSeeder::class,
            NumberingSequenceSeeder::class,
            DictionarySeeder::class,
            NotificationRuleSeeder::class,
        ]);
    }
}
