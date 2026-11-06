<?php

declare(strict_types=1);

namespace App\Modules\Assets\Database\Seeders;

use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Database\Seeder;

/** Default asset categories — useful lives / residuals are PLACEHOLDER pending accountant sign-off (A-64). @see SRS M05-02 */
final class AssetCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Computers & laptops', 'capital_asset', 36, '0'], ['Office equipment', 'capital_asset', 60, '0'], ['Furniture & fittings', 'capital_asset', 96, '0'],
            ['Training equipment', 'capital_asset', 60, '0'], ['Motor vehicles', 'capital_asset', 60, '10'], ['Phones & small devices', 'low_value_equipment', 24, '0'],
        ] as [$name, $class, $life, $residual]) {
            AssetCategory::query()->firstOrCreate(['name' => $name], ['default_class' => $class, 'useful_life_months' => $life, 'residual_percent' => $residual, 'active' => true, 'notes' => 'PLACEHOLDER useful life / residual']);
        }
    }
}
