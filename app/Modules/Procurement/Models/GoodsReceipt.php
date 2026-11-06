<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Goods / service receipt against an issued vendor PO — the step between PO and vendor bill (M10-02).
 *
 * @see SRS M10-02, WF-02
 *
 * @property int $id
 * @property int $company_id
 * @property string $grn_no
 * @property int $vendor_po_id
 * @property int $vendor_id
 * @property Carbon $received_on
 * @property string $kind
 * @property string|null $notes
 * @property string $status
 * @property int $received_by
 * @property-read Collection<int, GoodsReceiptLine> $lines
 */
final class GoodsReceipt extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_on' => 'date', 'vendor_po_id' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'goods_receipts';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->received_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }
}
