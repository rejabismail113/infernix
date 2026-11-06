<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Maintenance / repair log. The actual cost is a LINK to an existing finance expense or bill (finance_links,
 * purpose repair) — the log never books money itself (M05-03, rule 12).
 *
 * @see SRS M05-03
 *
 * @property int $id
 * @property int $asset_id
 * @property string $kind
 * @property Carbon $reported_on
 * @property Carbon|null $completed_on
 * @property int|null $vendor_id
 * @property string $description
 * @property Money|null $estimated_cost
 * @property string $status
 * @property int $created_by
 */
final class AssetMaintenance extends Model
{
    use Auditable;

    protected $table = 'asset_maintenance';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reported_on' => 'date', 'completed_on' => 'date', 'estimated_cost' => MoneyCast::class];
    }
}
