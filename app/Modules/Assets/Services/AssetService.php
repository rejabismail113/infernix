<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Search\SearchIndexer;
use App\Modules\Assets\Exceptions\AssetRejected;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetCandidate;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetMaintenance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Asset register: register from a posted capital purchase (no new expense — UAT-03), manual registration
 * (opening balance / donation, no ledger impact), custodian assignment / handover / return, maintenance log
 * with repair cost LINKED to an existing finance record, capitalisation policy hints.
 *
 * @see SRS M05-01, M05-02, M05-03, M05-05, WF-02, UAT-03
 */
final class AssetService
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly FinanceRecords $finance,
        private readonly StaffDirectory $staff,
        private readonly SearchIndexer $search,
        private readonly DepreciationService $depreciation,
    ) {}

    /** M05-02 policy: which class a purchase of this unit cost falls into (PLACEHOLDER thresholds, A-64). */
    public static function classify(string $unitCost): string
    {
        $v = BigDecimal::of($unitCost);

        return match (true) {
            $v->isGreaterThanOrEqualTo((string) config('infernix.assets.capital_threshold')) => 'capital_asset',
            $v->isGreaterThanOrEqualTo((string) config('infernix.assets.low_value_threshold')) => 'low_value_equipment',
            default => 'consumable',
        };
    }

    /** Listener entry point: remember a posted capital purchase line so it can be registered. Idempotent. */
    public function recordCandidate(string $sourceType, int $sourceId, int $lineId, string $description, string $amount, string $date): void
    {
        AssetCandidate::query()->firstOrCreate(['source_type' => $sourceType, 'source_line_id' => $lineId], [
            'source_id' => $sourceId, 'description' => $description, 'amount' => Money::fromDb($amount), 'purchase_date' => $date, 'status' => 'pending',
        ]);
    }

    /**
     * Register an asset FROM a purchase line. Several assets may share one line (e.g. 3 laptops) but their total
     * cost can never exceed it (service check + DB trigger). No journal and no expense are created.
     *
     * @param  array{name: string, cost?: string|null, category_id?: int|null, serial_no?: string|null, model?: string|null, location?: string|null, warranty_expires_on?: string|null, useful_life_months?: int|null, residual_value?: string|null, vendor_id?: int|null, project_id?: int|null, notes?: string|null}  $data
     */
    public function registerFromCandidate(AssetCandidate $candidate, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($candidate, $data, $actor): Asset {
            /** @var AssetCandidate $c */
            $c = AssetCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($c->status !== 'pending') {
                throw new AssetRejected('This purchase has already been registered or dismissed.');
            }
            $registered = Money::fromDb((string) (Asset::query()->where('purchase_source_type', $c->source_type)->where('purchase_line_id', $c->source_line_id)->sum('cost') ?: '0'));
            $remaining = $c->amount->minus($registered);
            $cost = isset($data['cost']) && $data['cost'] !== '' ? Money::myr((string) $data['cost']) : $remaining;
            if (! $cost->isPositive() || $cost->isGreaterThan($remaining)) {
                throw new AssetRejected('Asset cost must be positive and at most the unregistered purchase amount (RM '.Money::format($remaining).').');
            }
            $asset = $this->create($data + ['cost' => (string) $cost->getAmount()], $actor, [
                'acquisition_type' => 'purchase', 'purchase_source_type' => $c->source_type, 'purchase_line_id' => $c->source_line_id, 'candidate_id' => $c->id,
                'purchase_transaction_id' => $c->source_type === 'transaction' ? $c->source_id : null, 'purchase_bill_id' => $c->source_type === 'bill' ? $c->source_id : null,
                'acquired_on' => $c->purchase_date->toDateString(),
            ]);
            if ($cost->isEqualTo($remaining)) {
                $c->forceFill(['status' => 'registered'])->save();
            }
            $purchase = $this->finance->snapshot($c->source_type, $c->source_id);
            Audit::log('register_from_purchase', $asset, null, null, ['purchase' => $purchase['number'] ?? null, 'line_id' => $c->source_line_id, 'cost' => (string) $cost->getAmount()], null, $actor);

            return $asset;
        });
    }

    /**
     * Register without a purchase (opening balance brought forward, donation received). No ledger impact here:
     * any opening-balance journal is the accountant's adjusting entry in M01.
     *
     * @param  array<string, mixed>  $data
     */
    public function registerManual(array $data, User $actor): Asset
    {
        $type = (string) ($data['acquisition_type'] ?? 'opening_balance');
        if (! in_array($type, ['opening_balance', 'donation'], true)) {
            throw new AssetRejected('Purchased assets are registered from their posted purchase (Pending registrations), so the cost is counted once.');
        }

        return DB::transaction(fn (): Asset => $this->create($data, $actor, ['acquisition_type' => $type, 'acquired_on' => (string) ($data['acquired_on'] ?? today()->toDateString())]));
    }

    /** @param  array<string, mixed>  $data  descriptive fields only — cost and purchase reference never change */
    public function update(Asset $asset, array $data, User $actor): Asset
    {
        if ($asset->status === 'disposed') {
            throw new AssetRejected('A disposed asset is read-only.');
        }
        $fields = array_intersect_key($data, array_flip(['name', 'description', 'category_id', 'serial_no', 'model', 'location', 'warranty_expires_on', 'vendor_id', 'project_id', 'notes', 'asset_class']));
        $asset->forceFill($fields)->save();
        $this->index($asset);

        return $asset;
    }

    public function dismissCandidate(AssetCandidate $c, string $reason, User $actor): void
    {
        if ($c->status !== 'pending') {
            throw new AssetRejected('Only pending purchases can be dismissed.');
        }
        $c->forceFill(['status' => 'dismissed', 'dismissed_reason' => $reason, 'dismissed_by' => $actor->id])->save();
        Audit::log('dismiss', 'asset_candidate', $c->id, null, ['source' => "{$c->source_type}:{$c->source_id}"], $reason, $actor);
    }

    /** @param  array{staff_profile_id?: int|null, location?: string|null, assigned_on: string, expected_return_on?: string|null, condition_out?: string|null, note?: string|null}  $data */
    public function assign(Asset $asset, array $data, User $actor): AssetAssignment
    {
        return DB::transaction(function () use ($asset, $data, $actor): AssetAssignment {
            /** @var Asset $a */
            $a = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            if (in_array($a->status, ['disposed', 'lost'], true)) {
                throw new AssetRejected('A disposed or lost asset cannot be assigned.');
            }
            if (AssetAssignment::query()->where('asset_id', $a->id)->whereNull('returned_on')->exists()) {
                throw new AssetRejected('Record the return (handover) of the current assignment first.');
            }
            $staffId = isset($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null;
            if ($staffId === null && empty($data['location'])) {
                throw new AssetRejected('Choose a custodian or a location.');
            }
            if ($staffId !== null && $this->staff->snapshot($staffId) === null) {
                throw new AssetRejected('Unknown staff member.');
            }
            $assignment = AssetAssignment::query()->create([
                'asset_id' => $a->id, 'staff_profile_id' => $staffId, 'location' => $data['location'] ?? null, 'assigned_on' => $data['assigned_on'],
                'expected_return_on' => $data['expected_return_on'] ?? null, 'condition_out' => $data['condition_out'] ?? null, 'note' => $data['note'] ?? null, 'assigned_by' => $actor->id,
            ]);
            $a->forceFill(['status' => $staffId !== null ? 'assigned' : 'in_store', 'custodian_staff_id' => $staffId, 'location' => $data['location'] ?? $a->location])->save();

            return $assignment;
        });
    }

    /** @param  array{returned_on: string, condition_in?: string|null, note?: string|null, location?: string|null}  $data */
    public function returnAsset(Asset $asset, array $data, User $actor): void
    {
        DB::transaction(function () use ($asset, $data, $actor): void {
            /** @var AssetAssignment|null $open */
            $open = AssetAssignment::query()->where('asset_id', $asset->id)->whereNull('returned_on')->lockForUpdate()->first();
            if ($open === null) {
                throw new AssetRejected('This asset has no open assignment.');
            }
            if (Carbon::parse($data['returned_on'])->lt($open->assigned_on)) {
                throw new AssetRejected('Return date is before the assignment date.');
            }
            $open->forceFill(['returned_on' => $data['returned_on'], 'condition_in' => $data['condition_in'] ?? null, 'returned_by' => $actor->id,
                'note' => trim(($open->note ?? '')."\n".($data['note'] ?? '')) ?: null])->save();
            $asset->forceFill(['status' => $asset->status === 'in_repair' ? 'in_repair' : 'in_store', 'custodian_staff_id' => null, 'location' => $data['location'] ?? $asset->location])->save();
        });
    }

    /** @param  array{kind: string, reported_on: string, description: string, vendor_id?: int|null, estimated_cost?: string|null}  $data */
    public function logMaintenance(Asset $asset, array $data, User $actor): AssetMaintenance
    {
        if ($asset->status === 'disposed') {
            throw new AssetRejected('A disposed asset cannot be maintained.');
        }

        return DB::transaction(function () use ($asset, $data, $actor): AssetMaintenance {
            $m = AssetMaintenance::query()->create([
                'asset_id' => $asset->id, 'kind' => $data['kind'], 'reported_on' => $data['reported_on'], 'description' => $data['description'], 'vendor_id' => $data['vendor_id'] ?? null,
                'estimated_cost' => isset($data['estimated_cost']) && $data['estimated_cost'] !== '' ? Money::myr((string) $data['estimated_cost']) : null, 'status' => 'open', 'created_by' => $actor->id,
            ]);
            if ($data['kind'] === 'repair' && $asset->status !== 'assigned') {
                $asset->forceFill(['status' => 'in_repair'])->save();
            }

            return $m;
        });
    }

    public function completeMaintenance(AssetMaintenance $m, string $completedOn, User $actor): void
    {
        if ($m->status !== 'open') {
            throw new AssetRejected('This maintenance entry is already closed.');
        }
        DB::transaction(function () use ($m, $completedOn): void {
            $m->forceFill(['status' => 'completed', 'completed_on' => $completedOn])->save();
            $asset = Asset::query()->lockForUpdate()->findOrFail($m->asset_id);
            if ($asset->status === 'in_repair' && ! AssetMaintenance::query()->where('asset_id', $asset->id)->where('status', 'open')->where('kind', 'repair')->exists()) {
                $asset->forceFill(['status' => $asset->custodian_staff_id !== null ? 'assigned' : 'in_store'])->save();
            }
        });
        Audit::log('complete', $m, null, null, ['completed_on' => $completedOn], null, $actor);
    }

    /** Repair cost = a LINK to the expense/bill already recorded in M01 (purpose repair). */
    public function linkRepairCost(AssetMaintenance $m, string $sourceType, int $sourceId, User $actor): void
    {
        if (! in_array($sourceType, ['transaction', 'bill', 'claim'], true)) {
            throw new AssetRejected('Link the repair to the expense, bill or claim that paid for it.');
        }
        $this->finance->link('asset_maintenance', $m->id, $sourceType, $sourceId, 'repair', $actor);
    }

    public function index(Asset $asset): void
    {
        $this->search->index('asset', $asset->id, "{$asset->asset_no} {$asset->name}", trim(implode(' ', array_filter([$asset->serial_no, $asset->model, $asset->location, $asset->description]))), 'Assets');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $fixed
     */
    private function create(array $data, User $actor, array $fixed): Asset
    {
        $category = isset($data['category_id']) ? AssetCategory::query()->find((int) $data['category_id']) : null;
        $cost = Money::myr((string) ($data['cost'] ?? '0'));
        $residual = isset($data['residual_value']) && $data['residual_value'] !== '' ? Money::myr((string) $data['residual_value'])
            : Money::myr((string) $cost->getAmount()->multipliedBy($category->residual_percent ?? '0')->dividedBy(100, 2, RoundingMode::HALF_UP));
        $class = (string) ($data['asset_class'] ?? $category->default_class ?? (self::classify((string) $cost->getAmount()) === 'capital_asset' ? 'capital_asset' : 'low_value_equipment'));
        $asset = Asset::query()->create($fixed + [
            'company_id' => $actor->company_id,
            'asset_no' => $this->numbering->allocate((int) $actor->company_id, 'ASSET', (int) today()->format('Y'))['formatted'], // numbered in the registration year
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'category_id' => $category?->id, 'asset_class' => $class,
            'serial_no' => $data['serial_no'] ?? null, 'model' => $data['model'] ?? null, 'location' => $data['location'] ?? null, 'status' => 'in_store',
            'cost' => $cost, 'residual_value' => $residual, 'useful_life_months' => (int) ($data['useful_life_months'] ?? $category->useful_life_months ?? 36),
            'depreciation_method' => $class === 'capital_asset' ? 'straight_line' : 'none', 'warranty_expires_on' => $data['warranty_expires_on'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
        ]);
        $this->depreciation->schedule($asset);
        $this->index($asset);

        return $asset;
    }
}
