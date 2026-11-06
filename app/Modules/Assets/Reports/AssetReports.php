<?php

declare(strict_types=1);

namespace App\Modules\Assets\Reports;

use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Money\Money;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Reports\ReportTable;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDisposal;
use App\Modules\Assets\Services\DepreciationService;
use Illuminate\Support\Carbon;

/**
 * M05 outputs: asset register, custodian list, warranty expiries, disposal history.
 *
 * @see SRS M05 (Output / reporting), M11-05
 */
final class AssetReports
{
    public function __construct(private readonly StaffDirectory $staff, private readonly DepreciationService $depreciation, private readonly FinanceRecords $finance) {}

    /** @return array<string, mixed> */
    public function register(Carbon $asOf): array
    {
        $cost = Money::zero();
        $rows = [];
        foreach (Asset::query()->with('category')->orderBy('asset_no')->get() as $a) {
            $acc = $this->depreciation->accumulatedAsAt($a, $asOf);
            $rows[] = ['asset_no' => $a->asset_no, 'name' => $a->name, 'category' => $a->category?->name, 'class' => $a->asset_class, 'status' => $a->status, 'acquired_on' => $a->acquired_on->toDateString(),
                'source' => $a->purchase_source_type === null ? $a->acquisition_type : $a->purchase_source_type.' #'.($a->purchase_transaction_id ?? $a->purchase_bill_id),
                'cost' => (string) $a->cost->getAmount(), 'accumulated' => $acc, 'nbv' => (string) $a->cost->minus(Money::myr($acc))->getAmount(), 'location' => $a->location];
            if ($a->status !== 'disposed') {
                $cost = $cost->plus($a->cost);
            }
        }

        return ReportTable::make('Asset register as at '.$asOf->toDateString(), ['asset_no' => 'Asset no', 'name' => 'Name', 'category' => 'Category', 'class' => 'Class', 'status' => 'Status',
            'acquired_on' => 'Acquired', 'source' => 'Source', 'cost' => '#Cost', 'accumulated' => '#Accum. depreciation (schedule)', 'nbv' => '#NBV', 'location' => 'Location'], $rows,
            ['cost_in_use' => (string) $cost->getAmount()], ['Assets from purchases reference the posted purchase; registering an asset creates no expense (UAT-03).',
                DepreciationService::postingEnabled() ? 'Depreciation is posted monthly.' : 'Depreciation is scheduled but NOT posted (pending accountant decision).']);
    }

    /** @return array<string, mixed> */
    public function custodians(): array
    {
        $rows = Asset::query()->whereNotNull('custodian_staff_id')->orderBy('custodian_staff_id')->get()->map(fn (Asset $a): array => [
            'custodian' => $this->staff->snapshot((int) $a->custodian_staff_id)['name'] ?? '—', 'asset_no' => $a->asset_no, 'name' => $a->name, 'serial_no' => $a->serial_no,
            'since' => $a->assignments()->whereNull('returned_on')->value('assigned_on'), 'location' => $a->location,
        ])->values()->all();

        return ReportTable::make('Custodian list', ['custodian' => 'Custodian', 'asset_no' => 'Asset no', 'name' => 'Asset', 'serial_no' => 'Serial', 'since' => 'Since', 'location' => 'Location'], $rows);
    }

    /** @return array<string, mixed> */
    public function warrantyExpiries(Carbon $from, Carbon $to): array
    {
        $rows = Asset::query()->whereNotIn('status', ['disposed', 'lost'])->whereBetween('warranty_expires_on', [$from->toDateString(), $to->toDateString()])->orderBy('warranty_expires_on')->get()
            ->map(fn (Asset $a): array => ['asset_no' => $a->asset_no, 'name' => $a->name, 'serial_no' => $a->serial_no, 'warranty_expires_on' => $a->warranty_expires_on?->toDateString(),
                'days' => (string) today()->diffInDays($a->warranty_expires_on, false)])->values()->all();

        return ReportTable::make('Warranty expiries '.$from->toDateString().' – '.$to->toDateString(), ['asset_no' => 'Asset no', 'name' => 'Asset', 'serial_no' => 'Serial', 'warranty_expires_on' => 'Warranty ends', 'days' => 'Days left'], $rows);
    }

    /** @return array<string, mixed> */
    public function disposals(Carbon $from, Carbon $to): array
    {
        $ds = AssetDisposal::query()->with('asset')->whereBetween('disposal_date', [$from->toDateString(), $to->toDateString()])->orderBy('disposal_date')->get();
        $proceeds = $this->finance->linkedTotals('asset_disposal', $ds->pluck('id')->all(), 'proceeds');
        $rows = $ds->map(fn (AssetDisposal $d): array => ['disposal_no' => $d->disposal_no, 'asset' => "{$d->asset->asset_no} {$d->asset->name}", 'method' => $d->method, 'date' => $d->disposal_date->toDateString(),
            'status' => $d->status, 'cost' => $d->cost_snapshot !== null ? (string) $d->cost_snapshot->getAmount() : null, 'nbv' => $d->nbv_snapshot !== null ? (string) $d->nbv_snapshot->getAmount() : null,
            'proceeds' => $proceeds[$d->id] ?? '0.00', 'journal' => $this->finance->journalFor('asset_disposal', $d->id)['journal_no'] ?? null])->values()->all();

        return ReportTable::make('Disposal history '.$from->toDateString().' – '.$to->toDateString(), ['disposal_no' => 'Disposal', 'asset' => 'Asset', 'method' => 'Method', 'date' => 'Date', 'status' => 'Status',
            'cost' => '#Cost', 'nbv' => '#NBV written off', 'proceeds' => '#Proceeds (linked receipts)', 'journal' => 'Journal'], $rows, [], ['Proceeds are the receipts recorded in Finance and linked to the disposal; they are not posted twice.']);
    }
}
