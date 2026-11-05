<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Enums\RoleCode;
use App\Kernel\Identity\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @see SRS G-01, M12-01, §2
 *
 * @property int $id
 * @property int|null $company_id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $mfa_secret
 * @property array<int, string>|null $mfa_recovery_codes
 * @property Carbon|null $mfa_confirmed_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $deactivated_at
 * @property int|null $deactivated_by
 * @property Carbon|null $access_from
 * @property Carbon|null $access_until
 * @property string $locale
 * @property int|null $invited_by
 * @property int|null $staff_profile_id
 */
final class User extends Authenticatable implements PermissionResource
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'email', 'password', 'email_verified_at', 'locale', 'access_from', 'access_until', 'invited_by', 'staff_profile_id'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    /** @var list<string> */
    protected array $auditRedact = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'access_from' => 'datetime',
            'access_until' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public static function resourceName(): string
    {
        return 'users';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withPivot(['granted_at', 'granted_by']);
    }

    /** @return HasMany<UserScope, $this> */
    public function scopes(): HasMany
    {
        return $this->hasMany(UserScope::class);
    }

    /** @return list<RoleCode> */
    public function roleCodes(): array
    {
        return $this->roles->map(fn (Role $r): RoleCode => $r->roleCode())->values()->all();
    }

    public function hasRole(RoleCode ...$codes): bool
    {
        return $this->roles->contains(fn (Role $r): bool => in_array($r->roleCode(), $codes, true));
    }

    public function isOwner(): bool
    {
        return $this->hasRole(RoleCode::Owner);
    }

    public function mfaRequired(): bool
    {
        return $this->roles->contains(fn (Role $r): bool => $r->mfa_required);
    }

    public function mfaEnabled(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->mfa_secret !== null;
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** AUD time-boxed window; non-windowed users are always within window. */
    public function withinAccessWindow(?Carbon $at = null): bool
    {
        $at ??= now();
        if ($this->access_from !== null && $at->lt($this->access_from)) {
            return false;
        }
        if ($this->access_until !== null && $at->gte($this->access_until)) {
            return false;
        }

        return true;
    }

    public function hasScope(string $type, int $id): bool
    {
        return $this->scopes->contains(fn (UserScope $s): bool => $s->scope_type === $type && $s->scope_id === $id);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
