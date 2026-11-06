<?php

declare(strict_types=1);

namespace App\Modules\Assets\Events;

/**
 * One scheduled depreciation month is due for posting (only with FEATURE_DEPRECIATION_POSTING). Dispatched
 * synchronously inside the posting transaction; M01 posts it through PostingService (M05-04).
 *
 * @see SRS M05-04, docs/ARCHITECTURE.md §4
 */
final class AssetDepreciationDue
{
    public function __construct(
        public readonly int $scheduleId,
        public readonly string $assetNo,
        public readonly string $periodEnd,
        public readonly string $amount,
        public readonly int $actorId,
        public readonly ?int $projectId = null,
    ) {}
}
