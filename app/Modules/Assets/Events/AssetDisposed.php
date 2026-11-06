<?php

declare(strict_types=1);

namespace App\Modules\Assets\Events;

/**
 * An asset disposal was approved. Dispatched INSIDE the approval transaction so M01's disposal journal and the
 * disposal commit or roll back together. Carries IDs, dates and decimal strings only.
 *
 * @see SRS M05-04, docs/ARCHITECTURE.md §4
 */
final class AssetDisposed
{
    public function __construct(
        public readonly int $disposalId,
        public readonly string $disposalNo,
        public readonly int $assetId,
        public readonly string $assetNo,
        public readonly string $disposalDate,
        public readonly string $cost,
        public readonly string $accumulatedDepreciation,
        public readonly int $actorId,
        public readonly ?int $projectId = null,
    ) {}
}
