<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Joiner / mover / leaver access checklist, opened automatically by a staff lifecycle change (M13-04, WF-07).
 *
 * @see SRS M13-04, WF-07
 *
 * @property int $id
 * @property string $kind
 * @property int|null $staff_profile_id
 * @property int|null $user_id
 * @property string $trigger
 * @property list<array{key: string, label: string, done: bool, done_by?: int|null, done_at?: string|null, auto?: bool}> $items
 * @property string $status
 * @property int|null $completed_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
final class JmlChecklist extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['items' => 'array', 'completed_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'jml_checklists';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }
}
