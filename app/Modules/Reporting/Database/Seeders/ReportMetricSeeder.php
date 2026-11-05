<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Database\Seeders;

use App\Modules\Reporting\Models\ReportMetric;
use App\Modules\Reporting\Services\MetricService;
use Illuminate\Database\Seeder;

/**
 * Metric registry (M11 core fields): every dashboard number is defined here. Definitions are PLACEHOLDER
 * pending owner/accountant confirmation.
 *
 * @see SRS M11-01, M11-02, M11-04
 */
final class ReportMetricSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MetricService::DEFINITIONS as $id => $m) {
            ReportMetric::query()->updateOrCreate(['metric_id' => $id], [
                'metric_name' => $m['name'], 'definition' => $m['definition'], 'owner_role' => $m['owner'], 'source' => $m['source'],
                'calculation_rule' => $m['rule'], 'refresh_time' => 'live (on request)', 'security_scope' => $m['scope'],
                'notes' => 'PLACEHOLDER definition — confirm with owner/accountant',
            ]);
        }
    }
}
