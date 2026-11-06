<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Credential METADATA + vault item reference. No attribute can hold a secret (M13-02).
 * "Assigned" = I hold an active entitlement, or it belongs to a project / client of a project I can see
 * (client segregation, M13-06): a PM never sees credentials of another client.
 *
 * @see SRS M13-01, M13-02, M13-06
 *
 * @property int $id
 * @property int $company_id
 * @property string $credential_no
 * @property string $system_name
 * @property string $category
 * @property string $environment
 * @property string|null $url
 * @property string|null $username_reference
 * @property int $owner_id
 * @property int|null $project_id
 * @property int|null $client_id
 * @property string $vault_provider
 * @property string|null $vault_item_reference
 * @property string $sensitivity
 * @property int|null $rotation_interval_days
 * @property Carbon|null $last_rotated_on
 * @property Carbon|null $rotation_due
 * @property Carbon|null $expiry_date
 * @property string $status
 * @property bool $is_shared_service_account
 * @property string|null $shared_justification
 * @property int|null $shared_approved_by
 * @property string|null $notes
 * @property int $created_by
 * @property-read Collection<int, CredentialEntitlement> $entitlements
 */
final class CredentialMetadata extends Model implements PermissionResource
{
    use Auditable;

    public const CATEGORIES = ['cloud', 'bi', 'database', 'development', 'ai', 'corporate', 'client', 'api'];

    protected $table = 'credentials_metadata';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_rotated_on' => 'date', 'rotation_due' => 'date', 'expiry_date' => 'date', 'is_shared_service_account' => 'boolean',
            'owner_id' => 'integer', 'project_id' => 'integer', 'client_id' => 'integer', 'rotation_interval_days' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'credentials';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id || $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        if ($this->hasActiveEntitlement($user)) {
            return true;
        }
        $projects = app(ProjectDirectory::class);
        if ($this->project_id !== null) {
            $visible = $projects->visibleProjectIds($user);
            if ($visible === '*' || in_array($this->project_id, $visible, true)) {
                return true;
            }
        }
        if ($this->client_id !== null && $this->project_id === null) {
            $clients = $projects->visibleClientIds($user);

            return $clients === '*' || in_array($this->client_id, $clients, true);
        }

        return false;
    }

    public function hasActiveEntitlement(User $user): bool
    {
        return $this->entitlements()->where('user_id', $user->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', today()->toDateString()))->exists();
    }

    /** @return HasMany<CredentialEntitlement, $this> */
    public function entitlements(): HasMany
    {
        return $this->hasMany(CredentialEntitlement::class, 'credential_id');
    }

    /** @return HasMany<CredentialAccessRequest, $this> */
    public function accessRequests(): HasMany
    {
        return $this->hasMany(CredentialAccessRequest::class, 'credential_id');
    }

    /** @return HasMany<CredentialRotation, $this> */
    public function rotations(): HasMany
    {
        return $this->hasMany(CredentialRotation::class, 'credential_id')->orderByDesc('rotated_on');
    }
}
