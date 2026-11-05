<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registry entry for one dashboard metric (metric_id, definition, source, calculation rule, security scope).
 *
 * @see SRS M11 (Medan teras), M11-05
 *
 * @property int $id
 * @property string $metric_id
 * @property string $metric_name
 * @property string $definition
 * @property string $owner_role
 * @property string $source
 * @property string $calculation_rule
 * @property string $refresh_time
 * @property string $security_scope
 */
final class ReportMetric extends Model
{
    /** @var list<string> */
    protected $fillable = ['metric_id', 'metric_name', 'definition', 'owner_role', 'source', 'calculation_rule', 'refresh_time', 'security_scope', 'notes'];
}
