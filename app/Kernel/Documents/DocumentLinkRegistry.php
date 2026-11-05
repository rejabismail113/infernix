<?php

declare(strict_types=1);

namespace App\Kernel\Documents;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Validated morph map for entity_document_links.entity_type. Only registered types may be linked;
 * the map is also enforced on Eloquent morph relations (Relation::enforceMorphMap).
 *
 * @see SRS M06-01, M06-04, §6.3
 */
final class DocumentLinkRegistry
{
    /** @var array<string, LinkableEntityType> */
    private array $types = [];

    public function __construct(private readonly PermissionService $permissions) {}

    public function register(LinkableEntityType $type): void
    {
        if (! preg_match('/^[a-z_]+$/', $type->type)) {
            throw new InvalidArgumentException("Entity type [{$type->type}] must be snake_case.");
        }
        $this->types[$type->type] = $type;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): LinkableEntityType
    {
        return $this->types[$type] ?? throw new InvalidArgumentException("Unknown document entity type [{$type}].");
    }

    /** @return array<string, LinkableEntityType> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return array<string, class-string<Model>> */
    public function morphMap(): array
    {
        $map = [];
        foreach ($this->types as $type) {
            $map[$type->type] = $type->modelClass;
        }

        return $map;
    }

    public function find(string $type, int $id): ?Model
    {
        return $this->get($type)->modelClass::query()->find($id);
    }

    /**
     * Which link types may be used for this entity type.
     *
     * @return list<string>
     */
    public function linkTypesFor(string $type): array
    {
        $entity = $this->get($type);
        /** @var list<string> $global */
        $global = config('infernix.documents.link_types', []);

        return $entity->linkTypes !== [] ? $entity->linkTypes : $global;
    }

    /**
     * Ids of records of $type the user may VIEW ('*' = all). Modules supply efficient closures; the
     * fallback loads the table and asks the policy row by row (acceptable for small P1 tables).
     *
     * @return list<int>|'*'
     */
    public function accessibleIds(string $type, User $user): array|string
    {
        $entity = $this->get($type);
        if ($entity->accessibleIds !== null) {
            $ids = ($entity->accessibleIds)($user);

            return $ids === '*' ? '*' : array_values(array_map('intval', $ids));
        }
        $scope = $this->permissions->scopeFor($user, $entity->resourceName(), PermissionAction::View);

        return match ($scope) {
            RecordScope::All => '*',
            RecordScope::None => [],
            default => $entity->modelClass::query()->get()
                ->filter(fn (Model $m): bool => Gate::forUser($user)->allows('view', $m))
                ->map(fn (Model $m): int => (int) $m->getKey())->values()->all(),
        };
    }
}
