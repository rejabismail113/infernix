<?php

declare(strict_types=1);

namespace App\Modules\Finance\Events;

/**
 * A capital purchase was posted to Fixed Assets (no expense line). M05 offers "Register asset" from it,
 * referencing the purchase — never creating a second expense (WF-02, UAT-03).
 *
 * @see SRS M05-01, M01-10
 */
final class AssetQualifyingPurchaseRecorded
{
    public function __construct(
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly ?int $sourceLineId,
        public readonly string $description,
        public readonly string $amount,
        public readonly string $purchaseDate,
    ) {}
}
