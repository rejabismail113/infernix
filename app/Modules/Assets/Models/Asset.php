<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Kernel\People\Contracts\StaffDirectory;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Fixed asset / tracked low-value equipment. A purchased asset references its posted purchase line
 * (purchase_transaction_id or purchase_bill_id + purchase_line_id) — never a second expense (UAT-03).
 *
 * @see SRS M05-01, M05-02, M05-03, M05-04, M05-05
 *
 * @property int $id
 * @property int $company_id
 * @property string $asset_no
 * @property string $name
 * @property string|null $description
 * @property int|null $category_id
 * @property string $asset_class
 * @property string|null $serial_no
 * @property string|null $model
 * @property string|null $location
 * @property string $status
 * @property string $acquisition_type
 * @property string|null $purchase_source_type
 * @property int|null $purchase_transaction_id
 * @property int|null $purchase_bill_id
 * @property int|null $purchase_line_id
 * @property int|null $candidate_id
 * @property Carbon $acquired_on
 * @property Money $cost
 * @property Money $residual_value
 * @property int $useful_life_months
 * @property string $depreciation_method
 * @property Carbon|null $warranty_expires_on
 * @property int|null $custodian_staff_id
 * @property int|null $vendor_id
 * @property int|null $project_id
 * @property string|null $notes
 * @property Carbon|null $disposed_at
 * @property int $created_by
 * @property-read AssetCategory|null $category
 * @property-read Collection<int, AssetAssignment> $assignments
 * @property-read Collection<int, AssetMaintenance> $maintenance
 * @property-read Collection<int, AssetDepreciation> $depreciation
 * @property-read Collection<int, AssetDisposal> $disposals
 */
final class Asset extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['acquired_on' => 'date', 'warranty_expires_on' => 'date', 'disposed_at' => 'datetime', 'cost' => MoneyCast::class, 'residual_value' => MoneyCast::class,
            'useful_life_months' => 'integer', 'custodian_staff_id' => 'integer', 'project_id' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'assets';
    }

    /** "Own" = the asset is in my custody. */
    public function isOwnedBy(User $user): bool
    {
        return $this->custodian_staff_id !== null && $this->custodian_staff_id === app(StaffDirectory::class)->idForUser($user);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->isOwnedBy($user);
    }

    /** @return array{type: string, id: int, line_id: int|null}|null */
    public function purchaseRef(): ?array
    {
        return $this->purchase_source_type === null ? null : ['type' => $this->purchase_source_type, 'id' => (int) ($this->purchase_transaction_id ?? $this->purchase_bill_id), 'line_id' => $this->purchase_line_id];
    }

    /** @return BelongsTo<AssetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class);
    }

    /** @return HasMany<AssetAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('assigned_on')->orderByDesc('id');
    }

    /** @return HasMany<AssetMaintenance, $this> */
    public function maintenance(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class)->orderByDesc('reported_on')->orderByDesc('id');
    }

    /** @return HasMany<AssetDepreciation, $this> */
    public function depreciation(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('period_end');
    }

    /** @return HasMany<AssetDisposal, $this> */
    public function disposals(): HasMany
    {
        return $this->hasMany(AssetDisposal::class)->orderByDesc('id');
    }
}
