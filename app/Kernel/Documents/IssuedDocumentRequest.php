<?php

declare(strict_types=1);

namespace App\Kernel\Documents;

use App\Kernel\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * Everything needed to render and register an externally-numbered document (receipt, payment voucher).
 * Money values are decimal strings — never floats.
 *
 * @see SRS M03-06, M03-08
 */
final class IssuedDocumentRequest
{
    /**
     * @param  array<string, mixed>  $counterparty  snapshot (name, address, …)
     * @param  list<array{ref: string, description: string, amount: string}>  $lines
     * @param  array<string, mixed>  $extra  template-specific data (approvals, bank, method, …)
     */
    public function __construct(
        public readonly string $type,
        public readonly string $documentNo,
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly string $counterpartyType,
        public readonly array $counterparty,
        public readonly array $lines,
        public readonly string $total,
        public readonly Carbon $issueDate,
        public readonly LinkTarget $link,
        public readonly User $actor,
        public readonly array $extra = [],
    ) {}
}
