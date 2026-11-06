<?php

declare(strict_types=1);

namespace App\Kernel\Search;

use App\Kernel\Secrets\SecretRedactor;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of the global search index. Every title/body passes through the SecretRedactor, so a secret
 * typed into a record can never become searchable (M13-05, UAT-08). Modules index metadata only — never a
 * secret, identifier or bank field.
 *
 * @see SRS G-04, M13-05, UAT-08
 */
final class SearchIndexer
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    public function index(string $entityType, int $entityId, string $title, ?string $body, string $module): void
    {
        DB::table('search_index')->upsert([[
            'entity_type' => $entityType, 'entity_id' => $entityId, 'module' => $module,
            'title' => mb_substr($this->redactor->scrub($title), 0, 255), 'body' => $body === null ? null : $this->redactor->scrub($body), 'indexed_at' => now(),
        ]], ['entity_type', 'entity_id'], ['title', 'body', 'module', 'indexed_at']);
    }

    public function remove(string $entityType, int $entityId): void
    {
        DB::table('search_index')->where('entity_type', $entityType)->where('entity_id', $entityId)->delete();
    }
}
