<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS G-05
 *
 * @property int $id
 * @property string $event
 * @property list<string>|null $target_roles
 * @property list<int>|null $target_user_ids
 * @property string $channel
 * @property int $lead_days
 * @property bool $active
 * @property string|null $notes
 */
final class NotificationRule extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['event', 'target_roles', 'target_user_ids', 'channel', 'lead_days', 'active', 'notes'];

    public static function resourceName(): string
    {
        return 'notification_rules';
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
        return ['target_roles' => 'array', 'target_user_ids' => 'array', 'lead_days' => 'integer', 'active' => 'boolean'];
    }
}
