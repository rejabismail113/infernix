<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @see SRS M01-03, M01-04, §2.2
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $receipt_required
 * @property bool $is_medical
 * @property int|null $default_expense_category_id
 * @property Money|null $per_claim_limit
 * @property string|null $notes
 * @property bool $active
 */
final class ClaimType extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'receipt_required', 'is_medical', 'default_expense_category_id', 'per_claim_limit', 'notes', 'active'];

    public static function resourceName(): string
    {
        return 'dictionaries';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['receipt_required' => 'boolean', 'is_medical' => 'boolean', 'per_claim_limit' => MoneyCast::class, 'active' => 'boolean'];
    }

    /** @return HasMany<ClaimTypeRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ClaimTypeRate::class)->orderByDesc('version');
    }

    /** The rate version in force on a date — claim lines snapshot its id and value. */
    public function rateOn(?Carbon $on = null): ?ClaimTypeRate
    {
        $on ??= today();

        return $this->rates()->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $on))
            ->orderByDesc('version')->first();
    }
}
