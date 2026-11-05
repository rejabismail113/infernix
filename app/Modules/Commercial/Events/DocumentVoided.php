<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Events;

/** An issued document was voided (number retained, VOID copy stored). @see docs/ARCHITECTURE.md §4 */
final class DocumentVoided
{
    public function __construct(public readonly int $documentId, public readonly string $type) {}
}
