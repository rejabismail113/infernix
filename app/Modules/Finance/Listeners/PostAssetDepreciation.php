<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Kernel\Identity\Models\User;
use App\Modules\Assets\Events\AssetDepreciationDue;
use App\Modules\Finance\Services\LedgerGateway;
use Illuminate\Support\Carbon;

/** Posts one month of depreciation (feature-flagged in M05). Synchronous: failures roll back the caller. @see SRS M05-04 */
final class PostAssetDepreciation
{
    public function __construct(private readonly LedgerGateway $ledger) {}

    public function handle(AssetDepreciationDue $e): void
    {
        $this->ledger->postDepreciation($e->scheduleId, $e->assetNo, Carbon::parse($e->periodEnd), $e->amount, User::query()->findOrFail($e->actorId), $e->projectId);
    }
}
