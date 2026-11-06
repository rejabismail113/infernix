<?php

declare(strict_types=1);

namespace App\Modules\Assets\Listeners;

use App\Modules\Assets\Services\AssetService;
use App\Modules\Finance\Events\AssetQualifyingPurchaseRecorded;

/** A posted capital purchase becomes a "Register asset" candidate — no money moves here (UAT-03). @see SRS M05-01, WF-02 */
final class RecordAssetCandidate
{
    public function __construct(private readonly AssetService $assets) {}

    public function handle(AssetQualifyingPurchaseRecorded $e): void
    {
        if ($e->sourceLineId === null) {
            return;
        }
        $this->assets->recordCandidate($e->sourceType, $e->sourceId, $e->sourceLineId, $e->description, $e->amount, $e->purchaseDate);
    }
}
