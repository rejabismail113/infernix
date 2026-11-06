<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Kernel\Approval\ApprovalFlow;
use App\Kernel\Audit\Audit;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Modules\Assets\Events\AssetDisposed;
use App\Modules\Assets\Exceptions\AssetRejected;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetDisposal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Disposal workflow: draft → pending approval (matrix asset_disposal, maker-checker) → approved. On final approval
 * AssetDisposed is dispatched synchronously inside the transaction; M01 posts the disposal journal through
 * PostingService, so a locked period or unbalanced journal rolls the whole approval back (M05-04).
 *
 * @see SRS M05-04, M01-06, M01-08, UAT-11
 */
final class DisposalService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ApprovalFlow $flow,
        private readonly DepreciationService $depreciation,
        private readonly FinanceRecords $finance,
    ) {}

    /** @param  array{method: string, disposal_date: string, reason: string, expected_proceeds?: string|null}  $data */
    public function create(Asset $asset, array $data, User $actor): AssetDisposal
    {
        if ($asset->status === 'disposed') {
            throw new AssetRejected('This asset is already disposed.');
        }
        if (AssetDisposal::query()->where('asset_id', $asset->id)->whereNotIn('status', ['rejected', 'cancelled'])->exists()) {
            throw new AssetRejected('A disposal for this asset is already in progress.');
        }
        if (Carbon::parse($data['disposal_date'])->lt($asset->acquired_on)) {
            throw new AssetRejected('Disposal date is before the acquisition date.');
        }

        return DB::transaction(fn (): AssetDisposal => AssetDisposal::query()->create([
            'asset_id' => $asset->id,
            'disposal_no' => $this->numbering->allocate((int) $actor->company_id, 'ASSET_DISPOSAL', (int) Carbon::parse($data['disposal_date'])->format('Y'))['formatted'],
            'method' => $data['method'], 'disposal_date' => $data['disposal_date'], 'reason' => $data['reason'],
            'expected_proceeds' => Money::myr((string) (($data['expected_proceeds'] ?? '') === '' ? '0' : $data['expected_proceeds'])), 'status' => 'draft', 'created_by' => $actor->id,
        ]));
    }

    public function submit(AssetDisposal $d, User $actor): void
    {
        DB::transaction(function () use ($d, $actor): void {
            $locked = AssetDisposal::query()->lockForUpdate()->with('asset')->findOrFail($d->id);
            $this->flow->submit($locked, 'asset_disposal', $locked->asset->cost, $actor);
        });
    }

    public function approve(AssetDisposal $d, User $approver, ?string $overrideReason = null): AssetDisposal
    {
        return DB::transaction(function () use ($d, $approver, $overrideReason): AssetDisposal {
            /** @var AssetDisposal $locked */
            $locked = AssetDisposal::query()->lockForUpdate()->with('asset')->findOrFail($d->id);
            /** @var Asset $asset */
            $asset = Asset::query()->lockForUpdate()->findOrFail($locked->asset_id);
            $this->flow->approve($locked, 'asset_disposal', $asset->cost, $approver, $overrideReason, function () use ($locked, $asset, $approver): void {
                $accumulated = $this->depreciation->accumulatedPosted($asset);
                $nbv = $asset->cost->minus(Money::myr($accumulated));
                $locked->forceFill(['cost_snapshot' => $asset->cost, 'accumulated_snapshot' => Money::myr($accumulated), 'nbv_snapshot' => $nbv])->save();
                // Synchronous: M01 posts the disposal journal in this same transaction (rolls back together).
                Event::dispatch(new AssetDisposed($locked->id, $locked->disposal_no, $asset->id, $asset->asset_no, $locked->disposal_date->toDateString(),
                    (string) $asset->cost->getAmount(), $accumulated, $approver->id, $asset->project_id));
                $journal = $this->finance->journalFor('asset_disposal', $locked->id);
                $locked->forceFill(['journal_id' => $journal['id'] ?? null])->save();
                AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('returned_on')->update(['returned_on' => $locked->disposal_date->toDateString(), 'returned_by' => $approver->id, 'condition_in' => 'disposed']);
                $asset->forceFill(['status' => $locked->method === 'loss' ? 'lost' : 'disposed', 'disposed_at' => now(), 'custodian_staff_id' => null])->save();
                Audit::log('dispose', $asset, null, null, ['disposal' => $locked->disposal_no, 'journal' => $journal['journal_no'] ?? null, 'nbv' => (string) $nbv->getAmount()], $locked->reason, $approver);
            });

            return $locked->refresh();
        });
    }

    public function reject(AssetDisposal $d, User $actor, string $reason): void
    {
        DB::transaction(fn () => $this->flow->reject(AssetDisposal::query()->lockForUpdate()->findOrFail($d->id), 'asset_disposal', $actor, $reason));
    }

    public function cancel(AssetDisposal $d, User $actor, string $reason): void
    {
        if (! in_array($d->status, ['draft', 'pending_approval'], true)) {
            throw new AssetRejected('Only a draft or pending disposal can be cancelled.');
        }
        $d->forceFill(['status' => 'cancelled'])->save();
        Audit::log('cancel', $d, null, null, null, $reason, $actor);
    }

    /** Proceeds = the real receipt recorded in M01 (other income), LINKED here — never posted a second time. */
    public function linkProceeds(AssetDisposal $d, int $transactionId, User $actor): void
    {
        $snap = $this->finance->snapshot('transaction', $transactionId);
        if ($snap === null) {
            throw new AssetRejected('Unknown finance record.');
        }
        $this->finance->link('asset_disposal', $d->id, 'transaction', $transactionId, 'proceeds', $actor);
    }
}
