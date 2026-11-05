<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @see SRS M01-08, M12-05, UAT-09
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $period_no
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $locked_at
 * @property int|null $locked_by
 * @property string|null $lock_reason
 */
final class FiscalPeriod extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['fiscal_year_id', 'period_no', 'starts_on', 'ends_on', 'locked_at', 'locked_by', 'lock_reason'];

    public static function resourceName(): string
    {
        return 'fiscal_periods';
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
        return ['period_no' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'locked_at' => 'datetime'];
    }

    /** @return BelongsTo<FiscalYear, $this> */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** Period containing a date (used by posting to refuse locked periods). */
    public static function forDate(Carbon $date): ?self
    {
        return self::query()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
    }
}
