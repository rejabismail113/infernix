<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Events;

/** Cash received and posted Dr Bank / Cr AR (M03-04). @see docs/ARCHITECTURE.md §4 */
final class ReceiptPosted
{
    public function __construct(public readonly int $receiptId) {}
}
