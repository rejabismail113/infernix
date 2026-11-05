<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Events;

/** Accepted quotation converted to a project or an invoice draft without re-keying (M03-02, UAT-06). @see docs/ARCHITECTURE.md §4 */
final class QuotationConverted
{
    public function __construct(public readonly int $quotationId, public readonly string $target, public readonly int $targetId) {}
}
