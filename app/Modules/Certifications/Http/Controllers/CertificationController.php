<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Http\Controllers;

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Certifications\Models\Certification;
use App\Modules\Certifications\Models\Membership;
use App\Modules\Certifications\Models\Renewal;
use App\Modules\Certifications\Services\CertificationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Certifications, memberships and their renewal schedule (M09).
 *
 * @see SRS M09-01, M09-02, M09-03, M09-04
 */
final class CertificationController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly CertificationService $service) {}

    public function index(Request $request, StaffDirectory $staff, DocumentStore $documents, FinanceRecords $finance, PermissionService $permissions): Response
    {
        abort_unless($request->user()?->can('viewAny', Certification::class) || $request->user()?->can('viewAny', Membership::class), 403);
        $user = $this->user($request);
        $certs = $user->can('viewAny', Certification::class) ? $this->service->scoped(Certification::query(), $user, 'certifications')->orderBy('expiry_date')->get() : collect();
        $members = $user->can('viewAny', Membership::class) ? $this->service->scoped(Membership::query(), $user, 'memberships')->orderBy('period_end')->get() : collect();
        $renewals = Renewal::query()->where(fn ($q) => $q->where(fn ($w) => $w->where('renewable_type', 'certification')->whereIn('renewable_id', $certs->pluck('id')))
            ->orWhere(fn ($w) => $w->where('renewable_type', 'membership')->whereIn('renewable_id', $members->pluck('id'))))->orderBy('due_date')->get();
        $seeFinance = $permissions->can($user, PermissionAction::View, 'transactions') || $permissions->can($user, PermissionAction::View, 'bills');

        return Inertia::render('Certifications/Index', [
            'tab' => (string) $request->query('tab', 'certifications'),
            'certifications' => $certs->map(fn (Certification $c): array => ['id' => $c->id, 'holder_type' => $c->holder_type, 'staff_profile_id' => $c->staff_profile_id, 'holder' => $c->holderLabel(), 'name' => $c->name,
                'issuer' => $c->issuer, 'category' => $c->category, 'credential_no' => $c->credential_no, 'issue_date' => $c->issue_date?->toDateString(), 'expiry_date' => $c->expiry_date?->toDateString(),
                'no_expiry' => $c->no_expiry, 'verification_url' => $c->verification_url, 'status' => $c->status, 'notes' => $c->notes, 'documents' => $documents->linkedTo('certification', $c->id),
                'can_edit' => $user->can('update', $c)])->values(),
            'memberships' => $members->map(fn (Membership $m): array => ['id' => $m->id, 'holder_type' => $m->holder_type, 'staff_profile_id' => $m->staff_profile_id, 'holder' => $m->holderLabel(), 'organisation' => $m->organisation,
                'membership_no' => $m->membership_no, 'tier' => $m->tier, 'period_start' => $m->period_start->toDateString(), 'period_end' => $m->period_end->toDateString(), 'auto_renew' => $m->auto_renew,
                'annual_fee' => $m->annual_fee !== null ? (string) $m->annual_fee->getAmount() : null, 'status' => $m->status, 'notes' => $m->notes, 'can_edit' => $user->can('update', $m)])->values(),
            'renewals' => $renewals->map(function (Renewal $r) use ($finance, $seeFinance): array {
                $item = $r->renewable();

                return ['id' => $r->id, 'type' => $r->renewable_type, 'renewable_id' => $r->renewable_id, 'name' => $item instanceof Certification ? $item->name : ($item->organisation ?? '—'),
                    'holder' => $item?->holderLabel(), 'due_date' => $r->due_date->toDateString(), 'days' => (int) today()->diffInDays($r->due_date, false), 'status' => $r->status,
                    'renewed_on' => $r->renewed_on?->toDateString(), 'new_expiry_date' => $r->new_expiry_date?->toDateString(), 'note' => $r->note,
                    'costs' => $seeFinance ? $finance->linked('renewal', $r->id) : [], 'paid' => $finance->linkedTotals('renewal', [$r->id])[$r->id] ?? '0.00'];
            })->values(),
            'staff' => $user->can('create', Certification::class) ? $staff->options($user) : [],
            'can' => ['create_cert' => $user->can('create', Certification::class), 'create_membership' => $user->can('create', Membership::class), 'link_finance' => $seeFinance],
        ]);
    }

    public function storeCertification(Request $request): RedirectResponse
    {
        $this->authorize('create', Certification::class);

        return $this->attempt(fn () => $this->service->saveCertification(null, $request->validate($this->certRules()), $this->user($request)), __('Certification saved.'));
    }

    public function updateCertification(Request $request, Certification $certification): RedirectResponse
    {
        $this->authorize('update', $certification);

        return $this->attempt(fn () => $this->service->saveCertification($certification, $request->validate($this->certRules()), $this->user($request)), __('Certification saved.'));
    }

    public function storeMembership(Request $request): RedirectResponse
    {
        $this->authorize('create', Membership::class);

        return $this->attempt(fn () => $this->service->saveMembership(null, $request->validate($this->membershipRules()), $this->user($request)), __('Membership saved.'));
    }

    public function updateMembership(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('update', $membership);

        return $this->attempt(fn () => $this->service->saveMembership($membership, $request->validate($this->membershipRules()), $this->user($request)), __('Membership saved.'));
    }

    public function renew(Request $request, Renewal $renewal): RedirectResponse
    {
        $this->authorizeRenewal($renewal);
        $data = $request->validate(['renewed_on' => ['required', 'date'], 'new_expiry_date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->renew($renewal, $data['renewed_on'], $data['new_expiry_date'], $data['note'] ?? null, $this->user($request)), __('Renewal recorded.'));
    }

    public function lapse(Request $request, Renewal $renewal): RedirectResponse
    {
        $this->authorizeRenewal($renewal);
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->lapse($renewal, $data['note'], $this->user($request)), __('Marked as lapsed.'));
    }

    public function linkExpense(Request $request, Renewal $renewal, PermissionService $permissions): RedirectResponse
    {
        $this->authorizeRenewal($renewal);
        abort_unless($permissions->can($this->user($request), PermissionAction::View, 'transactions') || $permissions->can($this->user($request), PermissionAction::View, 'bills'), 403);
        $data = $request->validate(['source_type' => ['required', 'in:transaction,bill,claim'], 'source_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $this->service->linkExpense($renewal, $data['source_type'], (int) $data['source_id'], $this->user($request)), __('Renewal expense linked (no new expense).'));
    }

    private function authorizeRenewal(Renewal $r): void
    {
        $item = $r->renewable();
        abort_if($item === null, 404);
        $this->authorize('update', $item);
    }

    /** @return array<string, mixed> */
    private function certRules(): array
    {
        return ['holder_type' => ['required', 'in:staff,company'], 'staff_profile_id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:255'], 'issuer' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:40'], 'credential_no' => ['nullable', 'string', 'max:120'], 'issue_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date'],
            'no_expiry' => ['boolean'], 'verification_url' => ['nullable', 'url:https,http', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    /** @return array<string, mixed> */
    private function membershipRules(): array
    {
        return ['holder_type' => ['required', 'in:staff,company'], 'staff_profile_id' => ['nullable', 'integer'], 'organisation' => ['required', 'string', 'max:255'], 'membership_no' => ['nullable', 'string', 'max:120'],
            'tier' => ['nullable', 'string', 'max:120'], 'period_start' => ['required', 'date'], 'period_end' => ['required', 'date'], 'auto_renew' => ['boolean'],
            'annual_fee' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
