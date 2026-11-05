<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Identity\Models\ApprovalDelegation;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\DelegationService;
use App\Kernel\Money\Money;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS M12-03 */
final class DelegationController
{
    use AuthorizesRequests;

    public function __construct(private readonly DelegationService $delegations) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ApprovalDelegation::class);
        /** @var User $actor */
        $actor = $request->user();
        $rows = ApprovalDelegation::query()->with(['delegator:id,name', 'delegate:id,name'])->orderByDesc('id')->get()
            ->filter(fn (ApprovalDelegation $d): bool => $actor->can('view', $d))
            ->map(fn (ApprovalDelegation $d): array => [
                'id' => $d->id, 'delegator' => $d->delegator->name, 'delegate' => $d->delegate->name, 'entity_type' => $d->entity_type,
                'max_amount' => $d->max_amount?->getAmount()->__toString(), 'valid_from' => $d->valid_from->toDateString(), 'valid_to' => $d->valid_to->toDateString(),
                'reason' => $d->reason, 'version' => $d->version, 'supersedes_id' => $d->supersedes_id, 'revoked_at' => $d->revoked_at?->toIso8601String(),
            ])->values();

        return Inertia::render('Admin/Delegations/Index', [
            'delegations' => $rows,
            'users' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name']),
            'can' => ['create' => $actor->can('create', ApprovalDelegation::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ApprovalDelegation::class);
        $data = $this->validated($request);
        /** @var User $actor */
        $actor = $request->user();
        $this->delegations->create([
            'delegator_id' => $actor->isOwner() ? (int) ($data['delegator_id'] ?? $actor->id) : $actor->id,
            'delegate_id' => (int) $data['delegate_id'],
            'entity_type' => $data['entity_type'],
            'max_amount' => $data['max_amount'] !== null ? Money::myr($data['max_amount']) : null,
            'valid_from' => Carbon::parse($data['valid_from']),
            'valid_to' => Carbon::parse($data['valid_to']),
            'reason' => $data['reason'],
        ], $actor);

        return back()->with('success', __('Delegation created.'));
    }

    public function revise(Request $request, ApprovalDelegation $delegation): RedirectResponse
    {
        $this->authorize('update', $delegation);
        $data = $this->validated($request);
        /** @var User $actor */
        $actor = $request->user();
        $this->delegations->revise($delegation, [
            'delegate_id' => (int) $data['delegate_id'], 'entity_type' => $data['entity_type'],
            'max_amount' => $data['max_amount'] !== null ? Money::myr($data['max_amount']) : null,
            'valid_from' => Carbon::parse($data['valid_from']), 'valid_to' => Carbon::parse($data['valid_to']), 'reason' => $data['reason'],
        ], $actor);

        return back()->with('success', __('Delegation revised (new version).'));
    }

    public function revoke(Request $request, ApprovalDelegation $delegation): RedirectResponse
    {
        $this->authorize('update', $delegation);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $actor */
        $actor = $request->user();
        $this->delegations->revoke($delegation, $data['reason'], $actor);

        return back()->with('success', __('Delegation revoked.'));
    }

    /** @return array{delegator_id?:int|string|null, delegate_id:int|string, entity_type:string, max_amount:string|null, valid_from:string, valid_to:string, reason:string} */
    private function validated(Request $request): array
    {
        /** @var array{delegator_id?:int|string|null, delegate_id:int|string, entity_type:string, max_amount:string|null, valid_from:string, valid_to:string, reason:string} $data */
        $data = $request->validate([
            'delegator_id' => ['nullable', 'exists:users,id'],
            'delegate_id' => ['required', 'exists:users,id'],
            'entity_type' => ['required', 'in:claim,payment,invoice,bill,purchase_request,credit_note,asset_disposal'],
            'max_amount' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after:valid_from'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return $data;
    }
}
