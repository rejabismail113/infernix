<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Purchase request: draft → pending approval (matrix purchase_request, maker-checker) → approved → ordered
 * (vendor PO raised) | rejected | cancelled.
 *
 * @see SRS M10-02, WF-02
 *
 * @property int $id
 * @property int $company_id
 * @property string $pr_no
 * @property string $title
 * @property string $justification
 * @property int $requested_by
 * @property int|null $vendor_id
 * @property int|null $project_id
 * @property int|null $activity_id
 * @property Carbon|null $needed_by
 * @property Money $estimated_total
 * @property string $status
 * @property int $current_level
 * @property string|null $rejection_reason
 * @property int|null $vendor_po_id
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property-read int $created_by
 * @property-read Collection<int, PurchaseRequestLine> $lines
 * @property-read Vendor|null $vendor
 */
final class PurchaseRequest extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['needed_by' => 'date', 'approved_at' => 'datetime', 'estimated_total' => MoneyCast::class, 'current_level' => 'integer', 'requested_by' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'purchase_requests';
    }

    /** The ApprovalFlow maker-checker reads `created_by`: the requester is the maker. */
    public function getCreatedByAttribute(): int
    {
        return $this->requested_by;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->requested_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->status === 'pending_approval';
    }

    /** @return HasMany<PurchaseRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
