<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Versioned delegation of approval authority (never edited in place; DB trigger enforces).
 *
 * @see SRS M12-03, §2.1
 *
 * @property int $id
 * @property int $delegator_id
 * @property int $delegate_id
 * @property string $entity_type
 * @property Money|null $max_amount
 * @property Carbon $valid_from
 * @property Carbon $valid_to
 * @property string $reason
 * @property int $version
 * @property int|null $supersedes_id
 * @property Carbon|null $revoked_at
 * @property int $created_by
 */
final class ApprovalDelegation extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['delegator_id', 'delegate_id', 'entity_type', 'max_amount', 'valid_from', 'valid_to', 'reason', 'version', 'supersedes_id', 'revoked_at', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_amount' => MoneyCast::class,
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'revoked_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public static function resourceName(): string
    {
        return 'delegations';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->delegator_id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->delegate_id === $user->id;
    }

    /** @return BelongsTo<User, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /** @param  Builder<ApprovalDelegation>  $query */
    public function scopeActiveAt(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();
        $query->whereNull('revoked_at')->where('valid_from', '<=', $at)->where('valid_to', '>', $at)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('approval_delegations as newer')->whereColumn('newer.supersedes_id', 'approval_delegations.id'));
    }

    public function covers(string $entityType, ?Money $amount, ?Carbon $at = null): bool
    {
        $at ??= now();
        if ($this->revoked_at !== null || $this->entity_type !== $entityType) {
            return false;
        }
        if ($at->lt($this->valid_from) || $at->gte($this->valid_to)) {
            return false;
        }
        if ($this->max_amount !== null && $amount !== null && $amount->isGreaterThan($this->max_amount)) {
            return false;
        }

        return true;
    }
}
