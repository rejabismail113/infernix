<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Disposal: draft → pending approval (matrix asset_disposal + maker-checker) → approved (AssetDisposed → disposal
 * journal via PostingService). Proceeds are a separate real receipt in M01, linked here (purpose proceeds).
 *
 * @see SRS M05-04
 *
 * @property int $id
 * @property int $asset_id
 * @property string $disposal_no
 * @property string $method
 * @property Carbon $disposal_date
 * @property string $reason
 * @property Money $expected_proceeds
 * @property string $status
 * @property int $current_level
 * @property Money|null $cost_snapshot
 * @property Money|null $accumulated_snapshot
 * @property Money|null $nbv_snapshot
 * @property int|null $journal_id
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property-read Asset $asset
 */
final class AssetDisposal extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['disposal_date' => 'date', 'approved_at' => 'datetime', 'expected_proceeds' => MoneyCast::class, 'cost_snapshot' => MoneyCast::class,
            'accumulated_snapshot' => MoneyCast::class, 'nbv_snapshot' => MoneyCast::class, 'current_level' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'asset_disposals';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->status === 'pending_approval';
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
