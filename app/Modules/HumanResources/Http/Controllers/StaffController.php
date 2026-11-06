<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Http\Controllers;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\HumanResources\Models\Engagement;
use App\Modules\HumanResources\Models\StaffMemo;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\HumanResources\Services\StaffService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff directory & profile. NRIC/passport and bank numbers are always masked in lists, details and exports;
 * a full value is returned only by an audited reveal (HR/Owner; bank also Finance) after recent re-authentication.
 * Staff see only their own profile (M04-02).
 *
 * @see SRS M04-01, M04-05, M04-06, NFR-07
 */
final class StaffController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly StaffService $service, private readonly PermissionService $permissions, private readonly ProjectDirectory $projects) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', StaffProfile::class);
        /** @var User $user */
        $user = $request->user();
        $scope = $this->permissions->scopeFor($user, 'staff_profiles', PermissionAction::View);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive'], 'engagement_type' => ['nullable', 'in:employee,part_time,freelancer,intern']]);

        return Inertia::render('HR/Staff/Index', [
            'staff' => StaffProfile::query()->when($scope !== RecordScope::All, fn ($q) => $q->where('user_id', $user->id))
                ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['engagement_type'] ?? null, fn ($q, $v) => $q->where('engagement_type', $v))
                ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('legal_name', 'ilike', "%{$v}%")->orWhere('preferred_name', 'ilike', "%{$v}%")->orWhere('staff_no', 'ilike', "%{$v}%")))
                ->orderBy('legal_name')->limit(500)->get()->map(fn (StaffProfile $s): array => self::row($s)),
            'filters' => $f,
            'users' => $user->can('create', StaffProfile::class) ? User::query()->whereNotIn('id', StaffProfile::query()->whereNotNull('user_id')->select('user_id'))->orderBy('name')->get(['id', 'name', 'email'])->map(fn (User $u): array => ['id' => $u->id, 'label' => "{$u->name} ({$u->email})"]) : [],
            'can' => ['create' => $user->can('create', StaffProfile::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', StaffProfile::class);
        $data = $this->validated($request, true);

        return $this->attempt(function () use ($data, $request): RedirectResponse {
            $s = $this->service->create($data, $this->user($request));

            return redirect()->route('hr.staff.show', $s->id)->with('success', __('Staff :no created.', ['no' => $s->staff_no]));
        }, '');
    }

    public function show(Request $request, StaffProfile $staff, DocumentStore $documents): Response
    {
        $this->authorize('view', $staff);
        /** @var User $user */
        $user = $request->user();
        $staff->load(['engagements', 'bank']);
        $canBank = $this->permissions->can($user, PermissionAction::View, $staff->bank ?? 'staff_bank') || $staff->isOwnedBy($user);

        return Inertia::render('HR/Staff/Show', [
            'staff' => self::row($staff) + ['email' => $staff->email, 'phone' => $staff->phone, 'notes' => $user->can('update', $staff) ? $staff->notes : null, 'id_type' => $staff->id_type,
                'classification_reviewed_at' => $staff->classification_reviewed_at?->toIso8601String(), 'user_id' => $staff->user_id],
            'bank' => $canBank && $staff->bank !== null ? ['bank_name' => Gate::allows('reveal-staff-bank') || $staff->isOwnedBy($user) ? $staff->bank->bank_name : '••••', 'masked' => $staff->bank->masked(), 'updated_at' => $staff->bank->updated_at?->toIso8601String()] : null,
            'engagements' => $staff->engagements->map(fn (Engagement $e): array => ['id' => $e->id, 'version' => $e->version, 'rate_type' => $e->rate_type, 'rate_amount' => (string) $e->rate_amount->getAmount(),
                'effective_from' => $e->effective_from->toDateString(), 'effective_to' => $e->effective_to?->toDateString(), 'title' => $e->title, 'locked' => $e->locked_at !== null])->values(),
            'assignments' => $this->projects->assignmentsForStaff($staff->id),
            'memos' => StaffMemo::query()->where('staff_profile_id', $staff->id)->orderByDesc('delivered_at')->limit(50)->get(['id', 'subject', 'delivered_at', 'read_at', 'commercial_document_id']),
            'documents' => $documents->linkedTo('staff_profile', $staff->id),
            'can' => [
                'edit' => $user->can('update', $staff) && $this->permissions->scopeFor($user, 'staff_profiles', PermissionAction::Edit) === RecordScope::All,
                'revealId' => Gate::allows('reveal-staff-identifier'), 'revealBank' => Gate::allows('reveal-staff-bank'),
                'editBank' => Gate::allows('edit-staff-bank') || ($staff->isOwnedBy($user) && $this->permissions->can($user, PermissionAction::Edit, 'staff_bank')),
                'addRate' => $user->can('create', Engagement::class), 'attach' => $user->can('update', $staff),
            ],
        ]);
    }

    public function update(Request $request, StaffProfile $staff): RedirectResponse
    {
        $this->authorize('update', $staff);
        // Staff may edit their own contact details only; HR / Owner edit everything (M04-02).
        $full = $this->permissions->scopeFor($this->user($request), 'staff_profiles', PermissionAction::Edit) === RecordScope::All;
        $data = $full ? $this->validated($request, false) : $request->validate(['email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:40'], 'preferred_name' => ['nullable', 'string', 'max:190']]);

        return $this->attempt(fn () => $this->service->update($staff, $data, $this->user($request)), __('Profile updated.'));
    }

    public function revealId(Request $request, StaffProfile $staff): JsonResponse
    {
        $this->authorize('view', $staff);
        abort_unless(Gate::allows('reveal-staff-identifier'), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:300']])['reason'];

        return response()->json(['value' => $this->service->revealIdentifier($staff, $this->user($request), $reason)])->header('Cache-Control', 'no-store');
    }

    public function revealBank(Request $request, StaffProfile $staff): JsonResponse
    {
        $this->authorize('view', $staff);
        abort_unless(Gate::allows('reveal-staff-bank'), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:300']])['reason'];

        return response()->json(['value' => $this->service->revealBank($staff, $this->user($request), $reason)])->header('Cache-Control', 'no-store');
    }

    public function updateBank(Request $request, StaffProfile $staff): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(Gate::allows('edit-staff-bank') || ($staff->isOwnedBy($user) && $this->permissions->can($user, PermissionAction::Edit, 'staff_bank')), 403);
        $data = $request->validate(['bank_name' => ['required', 'string', 'max:100'], 'account_no' => ['required', 'string', 'max:30'], 'account_name' => ['required', 'string', 'max:190']]);

        return $this->attempt(fn () => $this->service->setBank($staff, $data, $user), __('Bank details saved (stored encrypted).'));
    }

    public function storeEngagement(Request $request, StaffProfile $staff): RedirectResponse
    {
        $this->authorize('create', Engagement::class);
        $data = $request->validate(['rate_type' => ['required', 'in:hourly,daily,per_session,fixed'], 'rate_amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'effective_from' => ['required', 'date'], 'title' => ['nullable', 'string', 'max:190'], 'agreement_document_id' => ['nullable', 'integer', 'exists:documents,id']]);

        return $this->attempt(fn () => $this->service->addEngagement($staff, $data, $this->user($request)), __('Rate agreement version added. Earlier versions are unchanged.'));
    }

    /** @return array<string, mixed> */
    public static function row(StaffProfile $s): array
    {
        return [
            'id' => $s->id, 'staff_no' => $s->staff_no, 'name' => $s->displayName(), 'legal_name' => $s->legal_name, 'id_masked' => $s->maskedId(), 'engagement_type' => $s->engagement_type,
            'classification' => $s->classification, 'position' => $s->position, 'joined_on' => $s->joined_on?->toDateString(), 'ended_on' => $s->ended_on?->toDateString(), 'status' => $s->status,
            'contractor_invoice_required' => $s->contractorInvoiceRequired(), 'has_user' => $s->user_id !== null,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $create): array
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('staff_profiles', 'user_id')->ignore($request->route('staff'))],
            'engagement_type' => ['required', 'in:employee,part_time,freelancer,intern'], 'legal_name' => ['required', 'string', 'max:190'], 'preferred_name' => ['nullable', 'string', 'max:190'],
            'id_type' => ['required', 'in:nric,passport'], 'id_number' => [$create ? 'nullable' : 'nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'string', 'max:120'], 'joined_on' => ['nullable', 'date'], 'ended_on' => ['nullable', 'date', 'after_or_equal:joined_on'],
            'classification' => ['required', 'in:employee,contractor'], 'status' => [$create ? 'nullable' : 'required', 'in:active,inactive'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (($data['engagement_type'] ?? null) === 'employee' && ($data['classification'] ?? null) === 'contractor') {
            throw ValidationException::withMessages(['classification' => __('An employee engagement cannot be classified as a contractor; review the classification (M04-05).')]);
        }

        return $data;
    }
}
