<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\DocumentScanner;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Documents\StoredDocument;
use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Enums\Classification;
use App\Modules\DocumentCentre\Exceptions\DocumentRejected;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Models\DocumentShare;
use App\Modules\DocumentCentre\Models\DocumentVersion;
use App\Modules\DocumentCentre\Models\EntityDocumentLink;
use App\Modules\DocumentCentre\Models\RetentionRule;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only code that writes to document storage. Objects are written under a fresh UUID key (never
 * overwritten), then metadata is committed; if the DB write fails the object is removed again.
 *
 * @see SRS M06-01 … M06-05, G-04, G-07, §6.3
 */
final class DocumentService implements DocumentStore
{
    public function __construct(
        private readonly FileInspector $inspector,
        private readonly DocumentScanner $scanner,
        private readonly DocumentLinkRegistry $registry,
    ) {}

    /**
     * @param  array{title?: string, classification?: string, retention_rule?: string, tags?: list<string>}  $meta
     */
    public function attach(UploadedFile $file, User $uploader, array $meta = [], ?LinkTarget $link = null, bool $requiredEvidence = false): StoredDocument
    {
        if ($link !== null) {
            $this->assertLinkable($link);
        }
        $info = $this->inspectAndScan($file, $uploader);
        $classification = Classification::tryFrom($meta['classification'] ?? 'confidential')
            ?? throw new DocumentRejected('Unknown classification.');
        $retention = $this->retentionRule($meta['retention_rule'] ?? null);
        $companyId = (int) $uploader->company_id;
        $key = $this->newKey($companyId, $link);
        $this->writeObject($key, $file);

        try {
            return DB::transaction(function () use ($uploader, $meta, $link, $requiredEvidence, $info, $classification, $retention, $companyId, $key): StoredDocument {
                $doc = Document::query()->create([
                    'company_id' => $companyId,
                    'owner_id' => $uploader->id,
                    'title' => trim((string) ($meta['title'] ?? '')) !== '' ? trim((string) $meta['title']) : pathinfo($info['original_name'], PATHINFO_FILENAME),
                    'classification' => $classification,
                    'tags' => array_values(array_unique(array_filter(array_map('trim', $meta['tags'] ?? [])))) ?: null,
                    'retention_rule_id' => $retention?->id,
                    'retention_until' => $retention !== null && $retention->basis === 'upload' ? today()->addMonths($retention->retain_months) : null,
                    'current_version_no' => 1,
                    'uploaded_at' => now(),
                ]);
                $version = $this->createVersion($doc, 1, $info, $key, $uploader);
                if ($link !== null) {
                    $this->createLink($doc, $link, $uploader, $requiredEvidence);
                }

                return new StoredDocument($doc->id, $version->id, 1, $info['hash'], $key);
            });
        } catch (Throwable $e) {
            $this->disk()->delete($key);
            throw $e;
        }
    }

    /** Re-upload: always a NEW version; previous versions stay intact and downloadable. */
    public function newVersion(Document $document, UploadedFile $file, User $uploader): DocumentVersion
    {
        $this->assertWritable($document);
        $info = $this->inspectAndScan($file, $uploader);
        $firstLink = $document->links()->orderBy('id')->first();
        $key = $this->newKey($document->company_id, $firstLink ? new LinkTarget($firstLink->entity_type, $firstLink->entity_id, $firstLink->link_type) : null);
        $this->writeObject($key, $file);

        try {
            return DB::transaction(function () use ($document, $info, $key, $uploader): DocumentVersion {
                /** @var Document $locked */
                $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
                $this->assertWritable($locked);
                $no = $locked->current_version_no + 1;
                $version = $this->createVersion($locked, $no, $info, $key, $uploader);
                $locked->forceFill(['current_version_no' => $no])->save();
                Audit::log('version_upload', $locked, null, ['version_no' => $no - 1], ['version_no' => $no, 'content_hash' => $info['hash'], 'size_bytes' => $info['size']], null, $uploader);

                return $version;
            });
        } catch (Throwable $e) {
            $this->disk()->delete($key);
            throw $e;
        }
    }

    public function link(int $documentId, LinkTarget $link, User $actor, bool $requiredEvidence = false): void
    {
        $this->assertLinkable($link);
        DB::transaction(function () use ($documentId, $link, $actor, $requiredEvidence): void {
            $doc = Document::query()->lockForUpdate()->findOrFail($documentId);
            if ($doc->isHardDeleted()) {
                throw new DocumentRejected('This document has been permanently deleted.');
            }
            $this->createLink($doc, $link, $actor, $requiredEvidence);
        });
    }

    public function hasLinked(string $entityType, int $entityId, string $linkType): bool
    {
        return EntityDocumentLink::query()->where('entity_type', $entityType)->where('entity_id', $entityId)->where('link_type', $linkType)
            ->whereHas('document', fn ($q) => $q->whereNull('hard_deleted_at'))->exists();
    }

    public function linkedTo(string $entityType, int $entityId): array
    {
        return EntityDocumentLink::query()->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->whereHas('document', fn ($q) => $q->whereNull('hard_deleted_at'))
            ->with(['document.currentVersion'])->orderBy('id')->get()
            ->map(fn (EntityDocumentLink $l): array => [
                'document_id' => $l->document_id, 'link_type' => $l->link_type, 'title' => $l->document->title, 'file_name' => $l->document->currentVersion?->file_name,
            ])->values()->all();
    }

    public function freeze(int $documentId, User $actor, string $reason): void
    {
        DB::transaction(function () use ($documentId, $actor, $reason): void {
            $doc = Document::query()->lockForUpdate()->findOrFail($documentId);
            if ($doc->frozen) {
                return;
            }
            $doc->forceFill(['frozen' => true, 'frozen_at' => now()])->save();
            Audit::log('freeze', $doc, null, ['frozen' => false], ['frozen' => true], $reason, $actor);
        });
    }

    public function archive(Document $doc, User $actor, ?string $reason = null): void
    {
        if ($doc->isArchived()) {
            return;
        }
        $doc->forceFill(['archived_at' => now(), 'archived_by' => $actor->id])->save();
        Audit::log('archive', $doc, null, null, ['archived_at' => now()->toIso8601String()], $reason, $actor);
    }

    public function unarchive(Document $doc, User $actor, ?string $reason = null): void
    {
        if (! $doc->isArchived()) {
            return;
        }
        $doc->forceFill(['archived_at' => null, 'archived_by' => null])->save();
        Audit::log('unarchive', $doc, null, null, ['archived_at' => null], $reason, $actor);
    }

    public function setLegalHold(Document $doc, bool $hold, string $reason, User $actor): void
    {
        $doc->forceFill(['legal_hold' => $hold, 'legal_hold_reason' => $hold ? $reason : null])->save();
        Audit::log($hold ? 'legal_hold_on' : 'legal_hold_off', $doc, null, null, ['legal_hold' => $hold], $reason, $actor);
    }

    public function share(Document $doc, User $target, ?Carbon $expiresAt, ?string $reason, User $actor): DocumentShare
    {
        if ($doc->isHardDeleted()) {
            throw new DocumentRejected('This document has been permanently deleted.');
        }
        if ($target->id === $doc->owner_id) {
            throw new DocumentRejected('The owner already has access.');
        }

        /** @var DocumentShare $share */
        $share = DocumentShare::query()->updateOrCreate(
            ['document_id' => $doc->id, 'user_id' => $target->id],
            ['permission' => 'view', 'expires_at' => $expiresAt, 'shared_by' => $actor->id, 'reason' => $reason],
        );
        Audit::log('share', $doc, null, null, ['user_id' => $target->id, 'expires_at' => $expiresAt?->toIso8601String()], $reason, $actor);

        return $share;
    }

    public function unshare(Document $doc, DocumentShare $share, User $actor): void
    {
        if ($share->document_id !== $doc->id) {
            throw new DocumentRejected('Share does not belong to this document.');
        }
        $share->delete();
        Audit::log('unshare', $doc, null, ['user_id' => $share->user_id], null, null, $actor);
    }

    /**
     * Permanent deletion: OWN-only (policy) + mandatory reason; refused under legal hold, inside the
     * retention period, or for frozen (issued) documents. Metadata and versions are kept as a tombstone;
     * the stored objects are removed after commit.
     */
    public function hardDelete(Document $doc, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DocumentRejected('A reason is required for permanent deletion.');
        }
        $keys = DB::transaction(function () use ($doc, $actor, $reason): array {
            /** @var Document $locked */
            $locked = Document::query()->lockForUpdate()->findOrFail($doc->id);
            if ($locked->isHardDeleted()) {
                throw new DocumentRejected('This document has already been permanently deleted.');
            }
            if ($locked->legal_hold) {
                throw new DocumentRejected('The document is under legal hold and cannot be deleted.');
            }
            if ($locked->frozen) {
                throw new DocumentRejected('Issued (frozen) documents cannot be deleted; void or supersede them instead.');
            }
            if ($locked->withinRetention()) {
                throw new DocumentRejected('The document is within its retention period (until '.$locked->retention_until?->toDateString().'); archive it instead.');
            }
            $keys = $locked->versions()->pluck('storage_key')->all();
            $locked->forceFill(['hard_deleted_at' => now(), 'hard_deleted_by' => $actor->id, 'hard_delete_reason' => $reason])->save();
            DocumentShare::query()->where('document_id', $locked->id)->delete();
            Audit::log('hard_delete', $locked, null, ['versions' => count($keys)], ['hard_deleted_at' => now()->toIso8601String()], $reason, $actor);

            return $keys;
        });

        foreach ($keys as $key) {
            try {
                $this->disk()->delete($key);
            } catch (Throwable $e) {
                Log::error('Document object could not be removed after hard delete', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('infernix.documents.disk'));
    }

    /** @return array{extension: string, mime: string, size: int, hash: string, original_name: string, scan_status: string, scanner: string} */
    private function inspectAndScan(UploadedFile $file, User $uploader): array
    {
        try {
            $info = $this->inspector->inspect($file);
        } catch (DocumentRejected $e) {
            Audit::log('upload_rejected', Document::class, null, null, ['file_name' => $file->getClientOriginalName(), 'reason' => $e->getMessage()], null, $uploader);
            throw $e;
        }
        $scan = $this->scanner->scan((string) $file->getRealPath());
        if (! $scan->status->allowsStorage()) {
            Audit::log('upload_rejected', Document::class, null, null, ['file_name' => $info['original_name'], 'scan_status' => $scan->status->value, 'scanner' => $scan->scanner], null, $uploader);
            throw new DocumentRejected($scan->status->value === 'infected' ? 'The file failed the malware scan.' : 'The file could not be scanned; upload refused.');
        }

        return $info + ['scan_status' => $scan->status->value, 'scanner' => $scan->scanner];
    }

    /** @param  array{extension: string, mime: string, size: int, hash: string, original_name: string, scan_status: string, scanner: string}  $info */
    private function createVersion(Document $doc, int $no, array $info, string $key, User $uploader): DocumentVersion
    {
        return DocumentVersion::query()->create([
            'document_id' => $doc->id, 'version_no' => $no, 'file_name' => $info['original_name'], 'extension' => $info['extension'],
            'mime_type' => $info['mime'], 'size_bytes' => $info['size'], 'content_hash' => $info['hash'], 'storage_key' => $key,
            'scan_status' => $info['scan_status'], 'scanner' => $info['scanner'], 'uploaded_by' => $uploader->id,
        ]);
    }

    private function createLink(Document $doc, LinkTarget $link, User $actor, bool $requiredEvidence): void
    {
        EntityDocumentLink::query()->firstOrCreate(
            ['entity_type' => $link->entityType, 'entity_id' => $link->entityId, 'document_id' => $doc->id, 'link_type' => $link->linkType],
            ['required_evidence' => $requiredEvidence, 'linked_by' => $actor->id],
        );
        /** @var list<string> $freezing */
        $freezing = config('infernix.documents.freezing_link_types', []);
        if (in_array($link->linkType, $freezing, true) && ! $doc->frozen) {
            $doc->forceFill(['frozen' => true, 'frozen_at' => now()])->save();
            Audit::log('freeze', $doc, null, ['frozen' => false], ['frozen' => true, 'link_type' => $link->linkType], 'linked as issued document', $actor);
        }
    }

    private function assertLinkable(LinkTarget $link): void
    {
        if (! $this->registry->has($link->entityType)) {
            throw new DocumentRejected("Documents cannot be linked to [{$link->entityType}].");
        }
        if (! in_array($link->linkType, $this->registry->linkTypesFor($link->entityType), true)) {
            throw new DocumentRejected("Link type [{$link->linkType}] is not allowed for {$link->entityType}.");
        }
        if ($this->registry->find($link->entityType, $link->entityId) === null) {
            throw new DocumentRejected('The record to link to does not exist.');
        }
    }

    private function assertWritable(Document $doc): void
    {
        if ($doc->isHardDeleted()) {
            throw new DocumentRejected('This document has been permanently deleted.');
        }
        if ($doc->frozen) {
            throw new DocumentRejected('This document is frozen (linked to an issued document); new versions are not allowed.');
        }
    }

    /** null/'' → default rule; 'none' → no retention period (deletable once authorised). */
    private function retentionRule(?string $code): ?RetentionRule
    {
        if ($code === 'none') {
            return null;
        }
        $code = $code === null || $code === '' ? (string) config('infernix.documents.default_retention_rule') : $code;

        return RetentionRule::query()->where('code', $code)->where('active', true)->first();
    }

    private function newKey(int $companyId, ?LinkTarget $link): string
    {
        $type = $link !== null ? $link->entityType : 'unlinked';
        $id = $link !== null ? $link->entityId : 0;

        return "{$companyId}/{$type}/{$id}/".Str::uuid()->toString();
    }

    private function writeObject(string $key, UploadedFile $file): void
    {
        $disk = $this->disk();
        if ($disk->exists($key)) {
            throw new DocumentRejected('Storage key collision; refusing to overwrite.');
        }
        $stream = fopen((string) $file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new DocumentRejected('The uploaded file could not be read.');
        }
        try {
            $disk->writeStream($key, $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
