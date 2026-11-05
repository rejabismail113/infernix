<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services;

use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Metadata search with row-level permission filtering applied IN SQL (DocumentAccess::scopeVisible),
 * so pagination counts, exports and reports can never include rows the user cannot view.
 *
 * @see SRS M06-04, G-04, G-11
 */
final class DocumentSearch
{
    public function __construct(private readonly DocumentAccess $access) {}

    /**
     * @param  array<string, mixed>  $f  q, classification, entity_type, entity_id, link_type, owner_id, tag, from, to, include_archived, legal_hold
     * @return Builder<Document>
     */
    public function query(User $user, array $f = []): Builder
    {
        $q = $this->access->scopeVisible(Document::query(), $user);

        if (! ($f['include_archived'] ?? false)) {
            $q->whereNull('documents.archived_at');
        }
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->where(function (Builder $w) use ($like, $term): void {
                $w->where('documents.title', 'ilike', $like)
                    ->orWhereExists(fn ($v) => $v->selectRaw('1')->from('document_versions')
                        ->whereColumn('document_versions.document_id', 'documents.id')->where('document_versions.file_name', 'ilike', $like))
                    ->orWhereRaw('documents.tags @> ?::jsonb', [json_encode([$term])]);
            });
        }
        if (! empty($f['classification'])) {
            $q->where('documents.classification', (string) $f['classification']);
        }
        if (! empty($f['tag'])) {
            $q->whereRaw('documents.tags @> ?::jsonb', [json_encode([(string) $f['tag']])]);
        }
        if (! empty($f['owner_id'])) {
            $q->where('documents.owner_id', (int) $f['owner_id']);
        }
        if (isset($f['legal_hold']) && $f['legal_hold'] !== '') {
            $q->where('documents.legal_hold', (bool) $f['legal_hold']);
        }
        if (! empty($f['from'])) {
            $q->where('documents.uploaded_at', '>=', (string) $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('documents.uploaded_at', '<', Carbon::parse((string) $f['to'])->addDay());
        }
        if (! empty($f['entity_type']) || ! empty($f['link_type'])) {
            $q->whereExists(function ($l) use ($f): void {
                $l->selectRaw('1')->from('entity_document_links')->whereColumn('entity_document_links.document_id', 'documents.id');
                if (! empty($f['entity_type'])) {
                    $l->where('entity_document_links.entity_type', (string) $f['entity_type']);
                }
                if (! empty($f['entity_id'])) {
                    $l->where('entity_document_links.entity_id', (int) $f['entity_id']);
                }
                if (! empty($f['link_type'])) {
                    $l->where('entity_document_links.link_type', (string) $f['link_type']);
                }
            });
        }

        return $q->orderByDesc('documents.uploaded_at')->orderByDesc('documents.id');
    }
}
