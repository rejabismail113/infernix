<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Versioned rate agreement. Locked (DB trigger) once used in a payment calculation: a new rate is a new version
 * and never changes what was already calculated (WF-05).
 *
 * @see SRS M04-03, WF-05
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property int $version
 * @property string $rate_type
 * @property Money $rate_amount
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $title
 * @property int|null $agreement_document_id
 * @property Carbon|null $locked_at
 * @property int $created_by
 * @property-read StaffProfile $staff
 */
final class Engagement extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'version', 'rate_type', 'rate_amount', 'effective_from', 'effective_to', 'title', 'agreement_document_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rate_amount' => MoneyCast::class, 'effective_from' => 'date', 'effective_to' => 'date', 'locked_at' => 'datetime', 'version' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'engagements';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->staff->user_id !== null && $this->staff->user_id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    public function unitLabel(): string
    {
        return match ($this->rate_type) {
            'hourly' => 'hour', 'daily' => 'day', 'per_session' => 'session', default => 'period',
        };
    }
}
