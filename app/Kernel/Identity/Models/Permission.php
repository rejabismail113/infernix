<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the matrix: role × resource × action → record_scope, versioned (policy history, M12-04).
 *
 * @see SRS G-02, M12-02
 *
 * @property int $id
 * @property int $role_id
 * @property string $resource
 * @property PermissionAction $action
 * @property RecordScope $record_scope
 * @property int $policy_version
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int|null $changed_by
 */
final class Permission extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['role_id', 'resource', 'action', 'record_scope', 'policy_version', 'effective_from', 'effective_to', 'changed_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => PermissionAction::class,
            'record_scope' => RecordScope::class,
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'policy_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @param  Builder<Permission>  $query */
    public function scopeEffective(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();
        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }
}
