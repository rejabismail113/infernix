<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Finance\Services\LedgerGateway;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Straight-line monthly depreciation schedule (first month = month of acquisition; last month absorbs rounding).
 * Posting is behind FEATURE_DEPRECIATION_POSTING pending the accountant's decision (A-65); without the flag the
 * schedule is informational and the ledger is untouched.
 *
 * @see SRS M05-04
 */
final class DepreciationService
{
    public function __construct(private readonly LedgerGateway $ledger) {}

    public static function postingEnabled(): bool
    {
        return (bool) config('infernix.features.depreciation_posting');
    }

    /** (Re)build the unposted part of the schedule. Posted rows are immutable (DB trigger). */
    public function schedule(Asset $asset): void
    {
        if ($asset->depreciation_method !== 'straight_line' || $asset->useful_life_months < 1) {
            AssetDepreciation::query()->where('asset_id', $asset->id)->whereNull('journal_id')->delete();

            return;
        }
        DB::transaction(function () use ($asset): void {
            AssetDepreciation::query()->where('asset_id', $asset->id)->whereNull('journal_id')->delete();
            $posted = AssetDepreciation::query()->where('asset_id', $asset->id)->whereNotNull('journal_id')->orderBy('period_end')->get();
            $base = BigDecimal::of((string) $asset->cost->getAmount())->minus((string) $asset->residual_value->getAmount());
            $months = $asset->useful_life_months;
            $monthly = $base->dividedBy($months, 2, RoundingMode::DOWN);
            $accumulated = BigDecimal::zero();
            foreach ($posted as $p) {
                $accumulated = $accumulated->plus((string) $p->amount->getAmount());
            }
            $start = $asset->acquired_on->copy()->endOfMonth();
            for ($i = $posted->count(); $i < $months; $i++) {
                $amount = $i === $months - 1 ? $base->minus($accumulated) : $monthly;
                if ($amount->isLessThanOrEqualTo(0)) {
                    break;
                }
                $accumulated = $accumulated->plus($amount);
                AssetDepreciation::query()->create([
                    'asset_id' => $asset->id, 'period_end' => $start->copy()->addMonthsNoOverflow($i)->endOfMonth()->toDateString(),
                    'amount' => Money::myr((string) $amount->toScale(2)), 'accumulated' => Money::myr((string) $accumulated->toScale(2)),
                    'nbv' => Money::myr((string) BigDecimal::of((string) $asset->cost->getAmount())->minus($accumulated)->toScale(2)),
                ]);
            }
        });
    }

    /** Depreciation already POSTED to the ledger (what a disposal derecognises). */
    public function accumulatedPosted(Asset $asset): string
    {
        $sum = AssetDepreciation::query()->where('asset_id', $asset->id)->whereNotNull('journal_id')->sum('amount');

        return (string) Money::fromDb((string) ($sum ?: '0'))->getAmount();
    }

    /** Schedule-based (book) accumulated depreciation as at a date — informational when posting is off. */
    public function accumulatedAsAt(Asset $asset, Carbon $date): string
    {
        $row = AssetDepreciation::query()->where('asset_id', $asset->id)->where('period_end', '<=', $date->toDateString())->orderByDesc('period_end')->first();

        return $row === null ? '0.00' : (string) $row->accumulated->getAmount();
    }

    /** Post due months up to $asOf (only with the feature flag on). @return int rows posted */
    public function postDue(Carbon $asOf, User $actor): int
    {
        if (! self::postingEnabled()) {
            return 0;
        }
        $count = 0;
        $rows = AssetDepreciation::query()->whereNull('journal_id')->where('period_end', '<=', $asOf->toDateString())
            ->whereHas('asset', fn ($q) => $q->whereNull('disposed_at'))->orderBy('period_end')->get();
        foreach ($rows as $row) {
            DB::transaction(function () use ($row, $actor, &$count): void {
                /** @var Asset $asset */
                $asset = Asset::query()->findOrFail($row->asset_id);
                $journalId = $this->ledger->postDepreciation($row->id, $asset->asset_no, $row->period_end, (string) $row->amount->getAmount(), $actor, $asset->project_id);
                $row->forceFill(['journal_id' => $journalId, 'posted_at' => now()])->save();
                $count++;
            });
        }

        return $count;
    }
}
