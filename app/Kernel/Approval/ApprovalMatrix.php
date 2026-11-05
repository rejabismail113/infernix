<?php

declare(strict_types=1);

namespace App\Kernel\Approval;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @see SRS M12-03
 *
 * @property int $id
 * @property string $entity_type
 * @property int $version
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $notes
 */
final class ApprovalMatrix extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['entity_type', 'version', 'effective_from', 'effective_to', 'notes', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'effective_to' => 'datetime', 'version' => 'integer'];
    }

    /** @return HasMany<ApprovalMatrixRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ApprovalMatrixRule::class)->orderBy('level');
    }

    /** @param  Builder<ApprovalMatrix>  $q */
    public function scopeEffective(Builder $q, ?Carbon $at = null): void
    {
        $at ??= now();
        $q->where('effective_from', '<=', $at)->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }
}
