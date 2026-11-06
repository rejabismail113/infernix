<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Controllers;

use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDisposal;
use App\Modules\Assets\Services\DisposalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Disposal workflow with maker-checker approval; approval posts the disposal journal (M05-04).
 *
 * @see SRS M05-04, UAT-11
 */
final class DisposalController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly DisposalService $service) {}

    /** @return array<string, mixed> */
    public static function row(AssetDisposal $d, FinanceRecords $finance): array
    {
        return ['id' => $d->id, 'disposal_no' => $d->disposal_no, 'method' => $d->method, 'disposal_date' => $d->disposal_date->toDateString(), 'reason' => $d->reason, 'status' => $d->status,
            'current_level' => $d->current_level, 'expected_proceeds' => (string) $d->expected_proceeds->getAmount(), 'nbv' => $d->nbv_snapshot !== null ? (string) $d->nbv_snapshot->getAmount() : null,
            'journal' => $finance->journalFor('asset_disposal', $d->id), 'proceeds' => $finance->linked('asset_disposal', $d->id), 'created_by' => $d->created_by];
    }

    public function store(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('view', $asset);
        $this->authorize('create', AssetDisposal::class);
        $data = $request->validate(['method' => ['required', 'in:sale,scrap,donation,loss,trade_in'], 'disposal_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000'],
            'expected_proceeds' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/']]);

        return $this->attempt(fn () => $this->service->create($asset, $data, $this->user($request)), __('Disposal drafted.'));
    }

    public function submit(Request $request, AssetDisposal $disposal): RedirectResponse
    {
        $this->authorize('update', $disposal);

        return $this->attempt(fn () => $this->service->submit($disposal, $this->user($request)), __('Submitted for approval.'));
    }

    public function approve(Request $request, AssetDisposal $disposal): RedirectResponse
    {
        $this->authorize('approve', $disposal);
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->approve($disposal, $this->user($request), $data['override_reason'] ?? null), __('Approved.'));
    }

    public function reject(Request $request, AssetDisposal $disposal): RedirectResponse
    {
        $this->authorize('approve', $disposal);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->reject($disposal, $this->user($request), $data['reason']), __('Rejected.'));
    }

    public function cancel(Request $request, AssetDisposal $disposal): RedirectResponse
    {
        $this->authorize('update', $disposal);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($disposal, $this->user($request), $data['reason']), __('Cancelled.'));
    }

    public function linkProceeds(Request $request, AssetDisposal $disposal): RedirectResponse
    {
        $this->authorize('update', $disposal);
        $data = $request->validate(['source_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $this->service->linkProceeds($disposal, (int) $data['source_id'], $this->user($request)), __('Proceeds receipt linked.'));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
