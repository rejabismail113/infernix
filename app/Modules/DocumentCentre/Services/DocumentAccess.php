<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services;

use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\DocumentCentre\Enums\Classification;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Models\EntityDocumentLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Single source of truth for document visibility. `canView()` (record-level, used by the policy) and
 * `scopeVisible()` (SQL, used by search/reports/exports) implement the SAME rule; a test asserts they
 * agree on a generated data set (M06-04, G-11).
 *
 * Rule: not hard-deleted
 *   AND documents.view scope: all | assigned(owner ∨ active share ∨ may view a linked record) | own(owner)
 *   AND (classification allowed for one of the user's roles ∨ owner ∨ active share)
 *
 * @see SRS M06-02, M06-04, §6.3, §2.1, NFR-02, NFR-07
 */
final class DocumentAccess
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly DocumentLinkRegistry $registry,
    ) {}

    public function canView(User $user, Document $doc): bool
    {
        if (! $this->permissions->userMayAct($user) || $doc->isHardDeleted()) {
            return false;
        }
        $scope = $this->permissions->scopeFor($user, 'documents', PermissionAction::View);
        $base = match ($scope) {
            RecordScope::All => true,
            RecordScope::Assigned => $doc->isOwnedBy($user) || $this->hasActiveShare($user, $doc) || $this->mayActOnLinked($user, $doc, 'view'),
            RecordScope::Own => $doc->isOwnedBy($user),
            RecordScope::None => false,
        };

        return $base && $this->classificationAllows($user, $doc);
    }

    /** Upload a new version, archive, share, link. Shares never grant edit. */
    public function canEdit(User $user, Document $doc): bool
    {
        if (! $this->canView($user, $doc)) {
            return false;
        }
        $scope = $this->permissions->scopeFor($user, 'documents', PermissionAction::Edit);

        return match ($scope) {
            RecordScope::All => true,
            RecordScope::Assigned => $doc->isOwnedBy($user) || $this->mayActOnLinked($user, $doc, 'update'),
            RecordScope::Own => $doc->isOwnedBy($user),
            RecordScope::None => false,
        };
    }

    public function isAssigned(User $user, Document $doc): bool
    {
        return $this->hasActiveShare($user, $doc) || $this->mayActOnLinked($user, $doc, 'view');
    }

    public function classificationAllows(User $user, Document $doc): bool
    {
        if ($doc->isOwnedBy($user) || $this->hasActiveShare($user, $doc)) {
            return true;
        }

        return in_array($doc->classification, $this->allowedClassifications($user), true);
    }

    /** @return list<Classification> */
    public function allowedClassifications(User $user): array
    {
        /** @var array<string, string|list<string>> $map */
        $map = config('infernix.documents.classification_access', []);
        $codes = array_map(fn ($r) => $r->value, $user->roleCodes());
        $allowed = [];
        foreach (Classification::cases() as $c) {
            $rule = $map[$c->value] ?? [];
            if ($rule === '*' || (is_array($rule) && array_intersect($rule, $codes) !== [])) {
                $allowed[] = $c;
            }
        }

        return $allowed;
    }

    /**
     * Restrict a documents query to rows the user may view (same rule as canView).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeVisible(Builder $query, User $user): Builder
    {
        $query->whereNull('documents.hard_deleted_at');
        if (! $this->permissions->userMayAct($user)) {
            return $query->whereRaw('false');
        }

        $scope = $this->permissions->scopeFor($user, 'documents', PermissionAction::View);
        $shareExists = fn (Builder $q) => $q->orWhereExists(fn ($s) => $s->selectRaw('1')->from('document_shares')
            ->whereColumn('document_shares.document_id', 'documents.id')->where('document_shares.user_id', $user->id)
            ->where(fn ($e) => $e->whereNull('document_shares.expires_at')->orWhere('document_shares.expires_at', '>', now())));

        match ($scope) {
            RecordScope::All => null,
            RecordScope::None => $query->whereRaw('false'),
            RecordScope::Own => $query->where('documents.owner_id', $user->id),
            RecordScope::Assigned => $query->where(function (Builder $q) use ($user, $shareExists): void {
                $q->where('documents.owner_id', $user->id);
                $shareExists($q);
                $this->orLinkedVisible($q, $user);
            }),
        };

        $allowed = array_map(fn (Classification $c) => $c->value, $this->allowedClassifications($user));
        $query->where(function (Builder $q) use ($user, $allowed, $shareExists): void {
            $q->whereIn('documents.classification', $allowed === [] ? ['__none__'] : $allowed)
                ->orWhere('documents.owner_id', $user->id);
            $shareExists($q);
        });

        return $query;
    }

    /** @param  Builder<Document>  $q */
    private function orLinkedVisible(Builder $q, User $user): void
    {
        foreach (array_keys($this->registry->all()) as $type) {
            $ids = $this->registry->accessibleIds($type, $user);
            if ($ids === []) {
                continue;
            }
            $q->orWhereExists(function ($l) use ($type, $ids): void {
                $l->selectRaw('1')->from('entity_document_links')
                    ->whereColumn('entity_document_links.document_id', 'documents.id')
                    ->where('entity_document_links.entity_type', $type);
                if ($ids !== '*') {
                    $l->whereIn('entity_document_links.entity_id', $ids);
                }
            });
        }
    }

    private function hasActiveShare(User $user, Document $doc): bool
    {
        $shares = $doc->relationLoaded('shares') ? $doc->shares : $doc->shares()->get();

        return $shares->contains(fn ($s): bool => $s->user_id === $user->id && $s->isActive());
    }

    private function mayActOnLinked(User $user, Document $doc, string $ability): bool
    {
        $links = $doc->relationLoaded('links') ? $doc->links : $doc->links()->get();

        return $links->contains(function (EntityDocumentLink $link) use ($user, $ability): bool {
            if (! $this->registry->has($link->entity_type)) {
                return false;
            }
            $entity = $this->registry->find($link->entity_type, $link->entity_id);

            return $entity !== null && Gate::forUser($user)->allows($ability, $entity);
        });
    }
}
