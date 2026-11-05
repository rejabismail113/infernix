<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Finance\Http\Presenters\FinanceOptions;
use App\Modules\Finance\Http\Presenters\FinancePresenter;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Services\ClaimService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Claims: staff portal (own claims only) and finance/approver view. List queries are scoped in SQL by the
 * user's record scope so other staff's claims never reach the page (UAT-01).
 *
 * @see SRS M01-03, M01-04, WF-01, UAT-01, UAT-02, §2.1
 */
final class ClaimController
{
    use AuthorizesRequests, HandlesDomainErrors;

    public function __construct(private readonly ClaimService $service, private readonly PermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Claim::class);
        /** @var User $user */
        $user = $request->user();
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'mine' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:60'], 'sort' => ['nullable', 'in:claim_no,total_amount,submitted_at'], 'dir' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $scope = $this->permissions->scopeFor($user, 'claims', PermissionAction::View);

        $q = Claim::query()->with('staff:id,name');
        if ($scope !== RecordScope::All || ($f['mine'] ?? false)) {
            $q->where(function ($w) use ($user, $scope): void {
                $w->where('staff_user_id', $user->id)->orWhere('created_by', $user->id);
                if ($scope === RecordScope::Assigned) {
                    $w->orWhere(fn ($p) => $p->where('status', 'pending_approval')->whereRaw('EXISTS (SELECT 1 FROM approval_delegations d WHERE d.delegate_id = ? AND d.entity_type = ? AND d.revoked_at IS NULL AND d.valid_from <= now() AND d.valid_to > now() AND (d.max_amount IS NULL OR d.max_amount >= claims.total_amount))', [$user->id, 'claim']));
                }
            });
        }
        $q->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('claim_no', 'ilike', "%{$v}%")->orWhere('purpose', 'ilike', "%{$v}%")))
            ->orderBy($f['sort'] ?? 'id', ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc');

        return Inertia::render('Finance/Claims/Index', [
            'claims' => $q->paginate((int) ($f['per_page'] ?? 25))->withQueryString()->through(fn (Claim $c) => FinancePresenter::claim($c)),
            'filters' => $f,
            'seesAll' => $scope === RecordScope::All,
            'can' => ['create' => $user->can('create', Claim::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Claim::class);

        return Inertia::render('Finance/Claims/Form', ['claim' => null, ...$this->formOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Claim::class);
        /** @var User $user */
        $user = $request->user();
        $data = $this->validated($request);

        return $this->attempt(fn () => redirect()->route('finance.claims.show', $this->service->create($user, $data, $user)), __('Claim saved as draft. Attach receipts, then submit.'));
    }

    public function show(Request $request, Claim $claim): Response
    {
        $this->authorize('view', $claim);
        /** @var User $user */
        $user = $request->user();
        $claim->load(['lines.claimType', 'staff:id,name', 'approvals.approver:id,name']);

        return Inertia::render('Finance/Claims/Show', [
            'claim' => FinancePresenter::claim($claim, $user, true),
            'approvals' => $claim->approvals->map(fn ($a): array => ['level' => $a->level, 'approver' => $a->approver->name, 'decision' => $a->decision, 'owner_override' => $a->owner_override, 'delegated' => $a->delegation_id !== null, 'reason' => $a->reason, 'decided_at' => $a->decided_at->toIso8601String()]),
            'can' => [
                'edit' => $claim->isEditableBy($user),
                'submit' => $claim->isEditableBy($user),
                // The service enforces maker-checker; the claimant only sees the buttons if they are an Owner (override).
                'decide' => $claim->status->value === 'pending_approval' && $user->can('approve', $claim) && (! $claim->isOwnedBy($user) || $user->isOwner()),
                'needsOverride' => $claim->isOwnedBy($user),
                'close' => $claim->status->value === 'paid' && $user->can('pay', $claim),
                'cancel' => $claim->isEditableBy($user),
                'pay' => $claim->status->value === 'finance_payable' && $user->can('pay', $claim),
            ],
        ]);
    }

    public function edit(Request $request, Claim $claim): Response
    {
        $this->authorize('update', $claim);
        abort_unless($claim->isEditableBy($request->user()), 403);
        $claim->load(['lines.claimType', 'staff:id,name']);

        return Inertia::render('Finance/Claims/Form', ['claim' => FinancePresenter::claim($claim, $request->user(), true), ...$this->formOptions()]);
    }

    public function update(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorize('update', $claim);
        /** @var User $user */
        $user = $request->user();
        $data = $this->validated($request);

        return $this->attempt(fn () => redirect()->route('finance.claims.show', $this->service->update($claim, $data, $user)), __('Claim updated.'));
    }

    public function submit(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorize('update', $claim);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->submit($claim, $user), __('Claim submitted for approval.'));
    }

    public function approve(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorize('view', $claim);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->approve($claim, $user, $data['override_reason'] ?? null), __('Claim approved.'));
    }

    public function reject(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorize('view', $claim);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'lines' => ['nullable', 'array'], 'lines.*' => ['nullable', 'string', 'max:300']]);
        $lines = array_filter($data['lines'] ?? [], fn ($v) => is_string($v) && trim($v) !== '');

        return $this->attempt(fn () => $this->service->reject($claim, $user, $data['reason'], $lines), __('Claim returned to the claimant for correction.'));
    }

    public function close(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorize('pay', $claim);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->close($claim, $user), __('Claim closed.'));
    }

    public function cancel(Request $request, Claim $claim): RedirectResponse
    {
        $this->authorize('update', $claim);
        abort_unless($claim->isEditableBy($request->user()), 403);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->cancel($claim, $user, (string) $request->input('reason', 'Cancelled by claimant')), __('Claim cancelled.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:500'], 'cost_centre_id' => ['nullable', 'exists:dictionary_items,id'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.id' => ['nullable', 'integer'], 'lines.*.claim_type_id' => ['required', 'exists:claim_types,id'],
            'lines.*.incurred_on' => ['required', 'date', 'before_or_equal:today'], 'lines.*.description' => ['required', 'string', 'max:300'],
            'lines.*.amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'lines.*.distance_km' => ['nullable', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            'lines.*.origin' => ['nullable', 'string', 'max:190'], 'lines.*.destination' => ['nullable', 'string', 'max:190'],
            'lines.*.category_id' => ['nullable', 'exists:dictionary_items,id'], 'lines.*.clinical_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return ['claimTypes' => FinanceOptions::claimTypes(), 'categories' => FinanceOptions::dictionary('expense_category'), 'costCentres' => FinanceOptions::dictionary('cost_centre')];
    }
}
