<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @see SRS M01-04, §2.2
 *
 * @property int $id
 * @property int $claim_type_id
 * @property Money $rate
 * @property string $unit
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $version
 * @property string|null $notes
 */
final class ClaimTypeRate extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['claim_type_id', 'rate', 'unit', 'effective_from', 'effective_to', 'version', 'notes'];

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
        return ['rate' => MoneyCast::class, 'effective_from' => 'date', 'effective_to' => 'date', 'version' => 'integer'];
    }
}
