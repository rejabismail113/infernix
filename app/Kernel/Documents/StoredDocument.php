<?php

declare(strict_types=1);

namespace App\Kernel\Documents;

/** Result of an upload, returned to callers in other modules (IDs only — no model coupling). */
final class StoredDocument
{
    public function __construct(
        public readonly int $documentId,
        public readonly int $versionId,
        public readonly int $versionNo,
        public readonly string $contentHash,
        public readonly string $storageKey,
    ) {}
}
