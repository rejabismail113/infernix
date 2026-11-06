<?php

declare(strict_types=1);

namespace App\Kernel\Search;

use App\Kernel\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Global search over the redacted index, filtered per record through the owning module's policy.
 *
 * @see SRS G-04, M13-05, NFR-02
 */
final class SearchService
{
    public function __construct(private readonly SearchRegistry $registry) {}

    /** @return list<array{type: string, type_label: string, id: int, title: string, module: string, url: string}> */
    public function search(User $user, string $q, int $limit = 30): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $rows = DB::table('search_index')->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('body', 'ilike', $like))
            ->orderByDesc('indexed_at')->limit(200)->get();
        $out = [];
        foreach ($rows as $r) {
            $type = $this->registry->get((string) $r->entity_type);
            if ($type === null) {
                continue;
            }
            $model = $type['model']::query()->find((int) $r->entity_id);
            if ($model === null || ! Gate::forUser($user)->allows('view', $model)) {
                continue;
            }
            $out[] = ['type' => (string) $r->entity_type, 'type_label' => $type['label'], 'id' => (int) $r->entity_id, 'title' => (string) $r->title, 'module' => (string) $r->module, 'url' => ($type['url'])($model)];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
