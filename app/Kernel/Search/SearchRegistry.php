<?php

declare(strict_types=1);

namespace App\Kernel\Search;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * entity_type → model class + URL builder. Results are loaded as models and re-checked with Gate `view`
 * (record-level policy) before they are shown, so the index can never widen anyone's access (NFR-02).
 *
 * @see SRS G-04, NFR-02
 */
final class SearchRegistry
{
    /** @var array<string, array{model: class-string<Model>, label: string, url: Closure(Model): string}> */
    private array $types = [];

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(Model): string  $url
     */
    public function register(string $entityType, string $model, string $label, Closure $url): void
    {
        $this->types[$entityType] = ['model' => $model, 'label' => $label, 'url' => $url];
    }

    /** @return array{model: class-string<Model>, label: string, url: Closure(Model): string}|null */
    public function get(string $entityType): ?array
    {
        return $this->types[$entityType] ?? null;
    }
}
