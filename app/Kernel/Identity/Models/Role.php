<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Identity\Enums\RoleCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @see SRS §2, G-02
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $mfa_required
 * @property bool $read_only
 */
final class Role extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'mfa_required', 'read_only'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mfa_required' => 'boolean', 'read_only' => 'boolean'];
    }

    public function roleCode(): RoleCode
    {
        return RoleCode::from($this->code);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withPivot(['granted_at', 'granted_by']);
    }

    /** @return HasMany<Permission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    public static function byCode(RoleCode|string $code): self
    {
        $code = $code instanceof RoleCode ? $code->value : $code;

        return self::query()->where('code', $code)->firstOrFail();
    }
}
