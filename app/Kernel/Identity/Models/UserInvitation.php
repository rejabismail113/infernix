<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @see SRS G-01, M12-01
 *
 * @property int $id
 * @property int $company_id
 * @property string $email
 * @property string|null $name
 * @property int $role_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int $invited_by
 */
final class UserInvitation extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'email', 'name', 'role_id', 'token_hash', 'expires_at', 'accepted_at', 'invited_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'users';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->invited_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
