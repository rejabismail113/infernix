<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Models;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only (DB trigger). Never updated or deleted by application code.
 *
 * @see SRS G-06, M12-04, NFR-03
 *
 * @property int $id
 * @property int|null $company_id
 * @property int|null $actor_id
 * @property string|null $actor_role
 * @property string $action
 * @property string $entity_type
 * @property int|null $entity_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property list<string>|null $redactions
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $reason
 * @property Carbon $occurred_at
 */
final class AuditEvent extends Model implements PermissionResource
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['company_id', 'actor_id', 'actor_role', 'action', 'entity_type', 'entity_id', 'before', 'after', 'redactions', 'ip', 'user_agent', 'request_id', 'reason', 'occurred_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'redactions' => 'array', 'occurred_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'audit_log';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->actor_id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
