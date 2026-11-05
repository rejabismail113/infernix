<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @see SRS G-09, M12-05
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $system
 */
final class Dictionary extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'system'];

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
        return ['system' => 'boolean'];
    }

    /** @return HasMany<DictionaryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class)->orderBy('sort_order')->orderBy('code');
    }
}
