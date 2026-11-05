<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Template library entry (one per document type). Layout changes are new append-only versions.
 *
 * @see SRS M03-07, M03-08
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $active
 * @property-read DocumentTemplateVersion|null $current
 */
final class DocumentTemplate extends Model implements PermissionResource
{
    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public static function resourceName(): string
    {
        return 'document_templates';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return HasMany<DocumentTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class)->orderByDesc('version');
    }

    /** @return HasOne<DocumentTemplateVersion, $this> */
    public function current(): HasOne
    {
        return $this->hasOne(DocumentTemplateVersion::class)->where('effective_from', '<=', now())->orderByDesc('version');
    }
}
