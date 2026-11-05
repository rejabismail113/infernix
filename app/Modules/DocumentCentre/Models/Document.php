<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Enums\Classification;
use App\Modules\DocumentCentre\Services\DocumentAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Document metadata. Access = documents permission scope + inheritance from linked records +
 * classification + explicit shares (SRS §6.3: "attachment permissions inherit from business record
 * plus document sensitivity"). See DocumentAccess.
 *
 * @see SRS M06-01 … M06-05, G-04, G-10
 *
 * @property int $id
 * @property int $company_id
 * @property int $owner_id
 * @property string $title
 * @property Classification $classification
 * @property list<string>|null $tags
 * @property int|null $retention_rule_id
 * @property Carbon|null $retention_until
 * @property bool $legal_hold
 * @property string|null $legal_hold_reason
 * @property int $current_version_no
 * @property bool $frozen
 * @property Carbon|null $frozen_at
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property Carbon|null $hard_deleted_at
 * @property int|null $hard_deleted_by
 * @property string|null $hard_delete_reason
 * @property Carbon $uploaded_at
 * @property-read User $owner
 * @property-read Collection<int, DocumentVersion> $versions
 * @property-read DocumentVersion|null $currentVersion
 * @property-read Collection<int, EntityDocumentLink> $links
 * @property-read Collection<int, DocumentShare> $shares
 * @property-read RetentionRule|null $retentionRule
 */
final class Document extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'owner_id', 'title', 'classification', 'tags', 'retention_rule_id', 'retention_until', 'legal_hold', 'legal_hold_reason', 'current_version_no', 'uploaded_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'classification' => Classification::class,
            'tags' => 'array',
            'retention_until' => 'date',
            'legal_hold' => 'boolean',
            'current_version_no' => 'integer',
            'frozen' => 'boolean',
            'frozen_at' => 'datetime',
            'archived_at' => 'datetime',
            'hard_deleted_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public static function resourceName(): string
    {
        return 'documents';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /** Assigned = explicit active share OR the user may view a linked business record. */
    public function isAssignedTo(User $user): bool
    {
        return app(DocumentAccess::class)->isAssigned($user, $this);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_no');
    }

    /** @return HasOne<DocumentVersion, $this> */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('version_no', 'max');
    }

    /** @return HasMany<EntityDocumentLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(EntityDocumentLink::class);
    }

    /** @return HasMany<DocumentShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(DocumentShare::class);
    }

    /** @return BelongsTo<RetentionRule, $this> */
    public function retentionRule(): BelongsTo
    {
        return $this->belongsTo(RetentionRule::class);
    }

    public function isHardDeleted(): bool
    {
        return $this->hard_deleted_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function withinRetention(): bool
    {
        return $this->retention_until !== null && $this->retention_until->isFuture();
    }
}
