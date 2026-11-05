<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @see SRS G-09, M12-05
 *
 * @property int $id
 * @property int $dictionary_id
 * @property string $code
 * @property string $label_en
 * @property string|null $label_ms
 * @property array<string, mixed>|null $meta
 * @property int|null $parent_id
 * @property int $sort_order
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property string|null $notes
 * @property bool $active
 */
final class DictionaryItem extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['dictionary_id', 'code', 'label_en', 'label_ms', 'meta', 'parent_id', 'sort_order', 'effective_from', 'effective_to', 'notes', 'active'];

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
        return ['meta' => 'array', 'sort_order' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'active' => 'boolean'];
    }

    /** @return BelongsTo<Dictionary, $this> */
    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(Dictionary::class);
    }

    /** @param  Builder<DictionaryItem>  $query */
    public function scopeEffective(Builder $query, ?Carbon $on = null): void
    {
        $on ??= today();
        $query->where('active', true)->whereDate('effective_from', '<=', $on)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>', $on));
    }
}
