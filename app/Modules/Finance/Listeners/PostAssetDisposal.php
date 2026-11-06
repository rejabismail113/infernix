<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Kernel\Identity\Models\User;
use App\Modules\Assets\Events\AssetDisposed;
use App\Modules\Finance\Services\LedgerGateway;
use Illuminate\Support\Carbon;

/**
 * Posts the disposal journal through PostingService (balanced, open period, numbered, audited). Runs synchronously:
 * if posting fails (e.g. locked period) the exception rolls back the disposal approval too.
 *
 * @see SRS M05-04, M01-06, M01-08
 */
final class PostAssetDisposal
{
    public function __construct(private readonly LedgerGateway $ledger) {}

    public function handle(AssetDisposed $e): void
    {
        $this->ledger->postAssetDisposal($e->disposalId, $e->disposalNo, $e->assetNo, Carbon::parse($e->disposalDate), $e->cost, $e->accumulatedDepreciation,
            User::query()->findOrFail($e->actorId), $e->projectId);
    }
}
