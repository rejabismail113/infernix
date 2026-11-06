<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

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
 * SaaS / service subscription. The renewal alert creates a REVIEW TASK only; money is recorded in M01 when the
 * supplier invoice / payment arrives and is then linked here (WF-06, UAT-12).
 *
 * @see SRS M10-04, M10-05, WF-06, UAT-12
 *
 * @property int $id
 * @property int $company_id
 * @property int $vendor_id
 * @property string $product
 * @property string|null $plan
 * @property int|null $license_owner_staff_id
 * @property int $seats
 * @property Money $price
 * @property string $currency
 * @property string $billing_cycle
 * @property bool $auto_renew
 * @property Carbon|null $start_date
 * @property Carbon $renewal_date
 * @property string $status
 * @property int|null $project_id
 * @property string|null $notes
 * @property int $created_by
 * @property-read Vendor $vendor
 * @property-read Collection<int, SubscriptionReview> $reviews
 */
final class Subscription extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['start_date' => 'date', 'renewal_date' => 'date', 'auto_renew' => 'boolean', 'price' => MoneyCast::class, 'seats' => 'integer', 'license_owner_staff_id' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'subscriptions';
    }

    /** "Own" = I am the licence owner (or recorded it). */
    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id || ($this->license_owner_staff_id !== null && $this->license_owner_staff_id === app(StaffDirectory::class)->idForUser($user));
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->isOwnedBy($user);
    }

    public function nextRenewal(Carbon $from): Carbon
    {
        return match ($this->billing_cycle) {
            'monthly' => $from->copy()->addMonthNoOverflow(),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3),
            default => $from->copy()->addYearNoOverflow(),
        };
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<SubscriptionReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(SubscriptionReview::class)->orderByDesc('renewal_date');
    }
}
