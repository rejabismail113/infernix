<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Events;

/** A quotation / invoice / credit note / receipt / PV was issued (number allocated, snapshot frozen). @see docs/ARCHITECTURE.md §4 */
final class DocumentIssued
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $type,
        public readonly ?string $counterpartyType = null,
        public readonly ?int $counterpartyId = null,
        public readonly ?string $subject = null,
    ) {}
}
