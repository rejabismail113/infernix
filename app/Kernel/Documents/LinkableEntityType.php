<?php

declare(strict_types=1);

namespace App\Kernel\Documents;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the validated morph map. Modules register their linkable records in their service provider.
 *
 * - `accessibleIds(User): list<int>|'*'`  — ids of this type the user may VIEW (drives document search security).
 * - `ownedIds(User): list<int>`          — ids this user OWNS (drives the `own` record scope).
 * - `evidenceSubjects(string $condition): array<int, string>` — id => label of records subject to a
 *   required-evidence rule with that condition (drives the missing-evidence report).
 * - `labelFor(Model): string`            — display label.
 *
 * @see SRS M06-01, M06-04, G-11
 */
final class LinkableEntityType
{
    /**
     * @param  class-string<Model&PermissionResource>  $modelClass
     * @param  list<string>  $linkTypes  allowed link types (empty = any from config infernix.documents.link_types)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $modelClass,
        public readonly string $label,
        public readonly array $linkTypes = [],
        public readonly ?Closure $accessibleIds = null,
        public readonly ?Closure $ownedIds = null,
        public readonly ?Closure $evidenceSubjects = null,
        public readonly ?Closure $labelFor = null,
    ) {}

    public function resourceName(): string
    {
        return $this->modelClass::resourceName();
    }

    public function label(Model $model): string
    {
        if ($this->labelFor !== null) {
            return (string) ($this->labelFor)($model);
        }
        $attrs = $model->getAttributes();

        return (string) ($attrs['name'] ?? $attrs['title'] ?? ($this->label.' #'.$model->getKey()));
    }

    /** @return list<int> */
    public function ownedIdsFor(User $user): array
    {
        return $this->ownedIds !== null ? array_values(array_map('intval', ($this->ownedIds)($user))) : [];
    }
}
