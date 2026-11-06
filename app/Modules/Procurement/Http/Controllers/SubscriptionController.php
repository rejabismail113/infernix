<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Crm\Contracts\VendorDirectory;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Procurement\Models\Subscription;
use App\Modules\Procurement\Models\SubscriptionReview;
use App\Modules\Procurement\Services\SubscriptionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subscriptions (M10-04/05): register, renewal review tasks (renew / cancel), linked supplier bills / payments.
 *
 * @see SRS M10-04, M10-05, WF-06, UAT-12
 */
final class SubscriptionController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly SubscriptionService $service) {}

    public function index(Request $request, PermissionService $permissions, StaffDirectory $staff, VendorDirectory $vendors, ProjectDirectory $projects, FinanceRecords $finance): Response
    {
        $this->authorize('viewAny', Subscription::class);
        $user = $this->user($request);
        $scope = $permissions->scopeFor($user, 'subscriptions', PermissionAction::View);
        $staffId = $staff->idForUser($user);
        $rows = Subscription::query()->with(['vendor', 'reviews'])
            ->when($scope !== RecordScope::All, fn ($q) => $q->where(fn ($w) => $w->where('created_by', $user->id)->orWhere('license_owner_staff_id', $staffId ?? 0)))
            ->orderBy('renewal_date')->get();
        $canEdit = $user->can('create', Subscription::class);
        $seeFinance = $permissions->can($user, PermissionAction::View, 'bills') || $permissions->can($user, PermissionAction::View, 'payments');
        $paid = $finance->linkedTotals('subscription', $rows->pluck('id')->all());

        return Inertia::render('Procurement/Subscriptions/Index', [
            'subscriptions' => $rows->map(fn (Subscription $s): array => ['id' => $s->id, 'vendor_id' => $s->vendor_id, 'vendor' => $s->vendor->name, 'product' => $s->product, 'plan' => $s->plan,
                'license_owner_staff_id' => $s->license_owner_staff_id, 'owner' => $s->license_owner_staff_id ? ($staff->snapshot($s->license_owner_staff_id)['name'] ?? null) : null,
                'seats' => $s->seats, 'price' => (string) $s->price->getAmount(), 'currency' => $s->currency, 'billing_cycle' => $s->billing_cycle, 'auto_renew' => $s->auto_renew,
                'start_date' => $s->start_date?->toDateString(), 'renewal_date' => $s->renewal_date->toDateString(), 'days' => SubscriptionService::daysTo($s->renewal_date), 'status' => $s->status,
                'project_id' => $s->project_id, 'notes' => $s->notes, 'annualised' => SubscriptionService::annualised($s), 'paid' => $paid[$s->id] ?? '0.00',
                'review' => ($r = $s->reviews->firstWhere('status', 'open')) !== null ? ['id' => $r->id, 'alert_level' => $r->alert_level, 'renewal_date' => $r->renewal_date->toDateString()] : null,
                'history' => $s->reviews->where('status', '!=', 'open')->map(fn (SubscriptionReview $r): array => ['renewal_date' => $r->renewal_date->toDateString(), 'status' => $r->status, 'note' => $r->decision_note])->values(),
                'finance' => $seeFinance ? $finance->linked('subscription', $s->id) : [], 'can_edit' => $user->can('update', $s)])->values(),
            'vendors' => $canEdit ? $vendors->options($user) : [], 'staff' => $canEdit ? $staff->options($user) : [], 'projects' => $canEdit ? $projects->options($user) : [],
            'can' => ['create' => $canEdit, 'link_finance' => $seeFinance],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Subscription::class);

        return $this->attempt(fn () => $this->service->save(null, $request->validate($this->rules()), $this->user($request)), __('Subscription saved.'));
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorize('update', $subscription);

        return $this->attempt(fn () => $this->service->save($subscription, $request->validate($this->rules()), $this->user($request)), __('Saved.'));
    }

    public function decide(Request $request, SubscriptionReview $review): RedirectResponse
    {
        $this->authorize('update', Subscription::query()->findOrFail($review->subscription_id));
        $data = $request->validate(['decision' => ['required', 'in:renew,cancel'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->decide($review, $data['decision'], $data['note'] ?? null, $this->user($request)), $data['decision'] === 'renew' ? __('Marked to renew (record the supplier bill in Finance when it arrives).') : __('Subscription cancelled.'));
    }

    public function linkFinance(Request $request, Subscription $subscription, PermissionService $permissions): RedirectResponse
    {
        $this->authorize('update', $subscription);
        abort_unless($permissions->can($this->user($request), PermissionAction::View, 'bills') || $permissions->can($this->user($request), PermissionAction::View, 'payments'), 403);
        $data = $request->validate(['source_type' => ['required', 'in:bill,transaction,payment'], 'source_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $this->service->linkFinance($subscription, $data['source_type'], (int) $data['source_id'], $this->user($request)), __('Finance record linked.'));
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorize('update', $subscription);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($subscription, $data['reason'], $this->user($request)), __('Cancelled.'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['vendor_id' => ['required', 'integer', 'exists:vendors,id'], 'product' => ['required', 'string', 'max:255'], 'plan' => ['nullable', 'string', 'max:255'],
            'license_owner_staff_id' => ['nullable', 'integer'], 'seats' => ['required', 'integer', 'min:1', 'max:100000'], 'price' => ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'currency' => ['required', 'in:MYR,USD,SGD,EUR,GBP'], 'billing_cycle' => ['required', 'in:monthly,quarterly,annual,one_off'], 'auto_renew' => ['boolean'],
            'start_date' => ['nullable', 'date'], 'renewal_date' => ['required', 'date'], 'project_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
