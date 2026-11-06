<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One month of the straight-line depreciation schedule. Posting is feature-flagged (infernix.assets.post_depreciation)
 * pending the accountant's decision; posted rows are immutable (trigger).
 *
 * @see SRS M05-04
 *
 * @property int $id
 * @property int $asset_id
 * @property Carbon $period_end
 * @property Money $amount
 * @property Money $accumulated
 * @property Money $nbv
 * @property int|null $journal_id
 * @property Carbon|null $posted_at
 * @property-read Asset $asset
 */
final class AssetDepreciation extends Model
{
    protected $table = 'asset_depreciation';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_end' => 'date', 'posted_at' => 'datetime', 'amount' => MoneyCast::class, 'accumulated' => MoneyCast::class, 'nbv' => MoneyCast::class];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
