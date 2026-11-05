<?php

declare(strict_types=1);

namespace App\Kernel\Documents\Contracts;

use App\Kernel\Documents\LinkTarget;
use App\Kernel\Documents\StoredDocument;
use App\Kernel\Identity\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * The only way other modules put files into the Document Centre (M06 implements it).
 * They never touch object storage themselves (docs/ARCHITECTURE.md rule 6).
 *
 * @see SRS M06-01, M06-02, G-07
 */
interface DocumentStore
{
    /**
     * @param  array{title?: string, classification?: string, retention_rule?: string, tags?: list<string>}  $meta
     */
    public function attach(UploadedFile $file, User $uploader, array $meta = [], ?LinkTarget $link = null, bool $requiredEvidence = false): StoredDocument;

    public function link(int $documentId, LinkTarget $link, User $actor, bool $requiredEvidence = false): void;

    /** Is a (non-deleted) document linked to this record with this link type? Used for mandatory evidence. */
    public function hasLinked(string $entityType, int $entityId, string $linkType): bool;

    /**
     * Ids of documents linked to a record (for showing evidence on module pages).
     *
     * @return list<array{document_id: int, link_type: string, title: string, file_name: string|null}>
     */
    public function linkedTo(string $entityType, int $entityId): array;

    /** Freeze a document (e.g. the PDF snapshot of an ISSUED commercial document): no new versions, ever. */
    public function freeze(int $documentId, User $actor, string $reason): void;
}
