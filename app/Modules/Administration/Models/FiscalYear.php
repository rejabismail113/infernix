<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @see SRS G-03, M01-08, M12-05
 *
 * @property int $id
 * @property int $company_id
 * @property int $year
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 */
final class FiscalYear extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'year', 'starts_on', 'ends_on', 'status'];

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
        return ['year' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return HasMany<FiscalPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('period_no');
    }
}
