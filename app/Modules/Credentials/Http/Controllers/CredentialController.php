<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Http\Controllers;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\Contracts\VaultClient;
use App\Modules\Credentials\Exceptions\CredentialRejected;
use App\Modules\Credentials\Models\CredentialAccessRequest;
use App\Modules\Credentials\Models\CredentialEntitlement;
use App\Modules\Credentials\Models\CredentialMetadata;
use App\Modules\Credentials\Models\CredentialRotation;
use App\Modules\Credentials\Models\JmlChecklist;
use App\Modules\Credentials\Services\AccessService;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\Credentials\Services\JmlService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Credentials & secrets (M13): metadata register, audited reveal (re-auth + entitlement), access requests,
 * rotation, joiner-mover-leaver checklists and the credential access audit view.
 *
 * @see SRS M13-01 … M13-06, WF-07, UAT-08
 */
final class CredentialController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly CredentialService $service, private readonly AccessService $access) {}

    public function index(Request $request, VaultClient $vault, ProjectDirectory $projects, ClientDirectory $clients): Response
    {
        $this->authorize('viewAny', CredentialMetadata::class);
        $user = $this->user($request);
        $f = $request->only(['q', 'category', 'status']);
        $rows = $this->service->visibleQuery($user)
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($w) => $w->where('system_name', 'ilike', '%'.$f['q'].'%')->orWhere('credential_no', 'ilike', '%'.$f['q'].'%')))
            ->when(! empty($f['category']), fn ($q) => $q->where('category', $f['category']))->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderBy('system_name')->limit(500)->get();
        $canCreate = $user->can('create', CredentialMetadata::class);

        return Inertia::render('Credentials/Index', [
            'credentials' => $rows->map(fn (CredentialMetadata $c): array => $this->row($c, $user))->values(),
            'categories' => CredentialMetadata::CATEGORIES, 'filters' => $f,
            'vault' => ['configured' => $vault->isConfigured(), 'provider' => $vault->provider()],
            'projects' => $canCreate ? $projects->options($user) : [], 'clients' => $canCreate ? $clients->options($user) : [],
            'users' => $canCreate ? User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'label' => $u->name]) : [],
            'pendingRequests' => CredentialAccessRequest::query()->with('credential')->where('status', 'pending')->get()
                ->filter(fn (CredentialAccessRequest $r): bool => $user->can('decideAccess', $r->credential))->map(fn (CredentialAccessRequest $r): array => $this->requestRow($r))->values(),
            'can' => ['create' => $canCreate, 'audit' => $user->can('export', CredentialMetadata::class) || $user->can('approve', CredentialMetadata::class), 'jml' => $user->can('viewAny', JmlChecklist::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CredentialMetadata::class);

        return $this->attempt(fn () => redirect()->route('credentials.credentials.show', $this->service->save(null, $request->validate($this->rules()), $this->user($request))->id), __('Credential metadata saved (no secret stored).'));
    }

    public function show(Request $request, CredentialMetadata $credential, VaultClient $vault, ProjectDirectory $projects, ClientDirectory $clients): Response
    {
        $this->authorize('view', $credential);
        $user = $this->user($request);
        $canEdit = $user->can('update', $credential);
        $canDecide = $user->can('decideAccess', $credential);

        return Inertia::render('Credentials/Show', [
            'credential' => $this->row($credential, $user) + ['url' => $credential->url, 'username_reference' => $credential->username_reference, 'vault_item_reference' => $credential->vault_item_reference,
                'deep_link' => $this->service->deepLink($credential), 'rotation_interval_days' => $credential->rotation_interval_days, 'last_rotated_on' => $credential->last_rotated_on?->toDateString(),
                'shared_justification' => $credential->shared_justification, 'notes' => $credential->notes, 'owner_id' => $credential->owner_id, 'project_id' => $credential->project_id, 'client_id' => $credential->client_id,
                'project' => $credential->project_id ? ($projects->snapshot($credential->project_id)['name'] ?? null) : null, 'client' => $credential->client_id ? ($clients->snapshot($credential->client_id)['name'] ?? null) : null],
            'vault' => ['configured' => $vault->isConfigured(), 'provider' => $vault->provider(), 'reveal_seconds' => (int) config('infernix.vault.reveal_seconds', 30)],
            'entitlements' => ($canDecide || $canEdit) ? $credential->entitlements()->orderByDesc('granted_at')->get()->map(fn (CredentialEntitlement $e): array => ['id' => $e->id, 'user' => User::query()->whereKey($e->user_id)->value('name'),
                'granted_at' => $e->granted_at->toDateTimeString(), 'expires_on' => $e->expires_on?->toDateString(), 'revoked_at' => $e->revoked_at?->toDateTimeString(), 'revoke_reason' => $e->revoke_reason, 'vault_sync' => $e->vault_sync])->values() : [],
            'requests' => $canDecide ? $credential->accessRequests()->orderByDesc('id')->limit(50)->get()->map(fn (CredentialAccessRequest $r): array => $this->requestRow($r))->values() : [],
            'myRequest' => CredentialAccessRequest::query()->where('credential_id', $credential->id)->where('requester_id', $user->id)->orderByDesc('id')->first(['status', 'created_at']),
            'rotations' => $credential->rotations()->limit(20)->get()->map(fn (CredentialRotation $r): array => ['rotated_on' => $r->rotated_on->toDateString(), 'by' => User::query()->whereKey($r->rotated_by)->value('name'), 'note' => $r->note])->values(),
            'projects' => $canEdit ? $projects->options($user) : [], 'clients' => $canEdit ? $clients->options($user) : [],
            'users' => ($canEdit || $canDecide) ? User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'label' => $u->name]) : [],
            'categories' => CredentialMetadata::CATEGORIES,
            'can' => ['edit' => $canEdit, 'reveal' => $user->can('revealSecret', $credential), 'request' => $user->can('requestAccess', $credential) && ! $credential->hasActiveEntitlement($user) && $credential->owner_id !== $user->id,
                'decide' => $canDecide],
        ]);
    }

    public function update(Request $request, CredentialMetadata $credential): RedirectResponse
    {
        $this->authorize('update', $credential);

        return $this->attempt(fn () => $this->service->save($credential, $request->validate($this->rules()), $this->user($request)), __('Saved.'));
    }

    public function status(Request $request, CredentialMetadata $credential): RedirectResponse
    {
        $this->authorize('update', $credential);
        $data = $request->validate(['status' => ['required', 'in:active,rotating,revoked,retired'], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->setStatus($credential, $data['status'], $this->user($request), $data['reason'] ?? null), __('Status updated.'));
    }

    public function rotate(Request $request, CredentialMetadata $credential): RedirectResponse
    {
        $this->authorize('update', $credential);
        $data = $request->validate(['rotated_on' => ['required', 'date', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->recordRotation($credential, $data['rotated_on'], $data['note'] ?? null, $this->user($request)), __('Rotation recorded.'));
    }

    /**
     * Reveal: route has RequireRecentAuth + throttle; here the matrix reveal right AND an active vault entitlement
     * are checked independently (M13-03). The value goes only into this no-store JSON response.
     */
    public function reveal(Request $request, CredentialMetadata $credential): JsonResponse
    {
        $this->authorize('view', $credential);
        abort_unless($this->user($request)->can('revealSecret', $credential), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:300']])['reason'];
        try {
            $value = $this->service->reveal($credential, $this->user($request), $reason);
        } catch (CredentialRejected $e) {
            return response()->json(['message' => $e->getMessage(), 'deep_link' => $this->service->deepLink($credential)], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json(['value' => $value, 'expires_in' => (int) config('infernix.vault.reveal_seconds', 30)])
            ->header('Cache-Control', 'no-store, private, max-age=0')->header('Pragma', 'no-cache');
    }

    public function requestAccess(Request $request, CredentialMetadata $credential): RedirectResponse
    {
        $this->authorize('view', $credential);
        abort_unless($this->user($request)->can('requestAccess', $credential), 403);
        $data = $request->validate(['justification' => ['required', 'string', 'min:10', 'max:1000'], 'access_until' => ['nullable', 'date', 'after:today']]);

        return $this->attempt(fn () => $this->access->request($credential, $this->user($request), $data['justification'], $data['access_until'] ?? null), __('Access requested from the credential owner.'));
    }

    /** Anyone with request rights may ask for access to a credential they can see listed (metadata only). */
    public function decide(Request $request, CredentialAccessRequest $accessRequest): RedirectResponse
    {
        $credential = CredentialMetadata::query()->findOrFail($accessRequest->credential_id);
        abort_unless($this->user($request)->can('decideAccess', $credential), 403);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->access->decide($accessRequest, (bool) $data['approve'], $this->user($request), $data['note'] ?? null), (bool) $data['approve'] ? __('Access granted.') : __('Request rejected.'));
    }

    public function grant(Request $request, CredentialMetadata $credential): RedirectResponse
    {
        abort_unless($this->user($request)->can('decideAccess', $credential), 403);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'expires_on' => ['nullable', 'date', 'after:today']]);
        abort_if((int) $data['user_id'] === $this->user($request)->id && ! $this->user($request)->isOwner(), 403);

        return $this->attempt(fn () => $this->access->grant($credential, User::query()->findOrFail((int) $data['user_id']), $this->user($request), null, $data['expires_on'] ?? null), __('Access granted.'));
    }

    public function revoke(Request $request, CredentialEntitlement $entitlement): RedirectResponse
    {
        abort_unless($this->user($request)->can('decideAccess', CredentialMetadata::query()->findOrFail($entitlement->credential_id)), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->access->revoke($entitlement, $this->user($request), $data['reason']), __('Access revoked.'));
    }

    /** Credential access audit view (reveals, grants, revokes, requests, rotations) — metadata only. */
    public function audit(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->can('export', CredentialMetadata::class) || $user->can('approve', CredentialMetadata::class), 403);
        $visible = $this->service->visibleQuery($user)->pluck('id');
        $events = AuditEvent::query()->where('entity_type', CredentialMetadata::class)->whereIn('entity_id', $visible)->orderByDesc('occurred_at')->limit(500)->get();
        $names = CredentialMetadata::query()->whereIn('id', $events->pluck('entity_id')->unique())->get(['id', 'credential_no', 'system_name'])->keyBy('id');
        $actors = User::query()->whereIn('id', $events->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Credentials/Audit', [
            'events' => $events->map(fn (AuditEvent $e): array => ['id' => $e->id, 'occurred_at' => (string) $e->occurred_at, 'action' => $e->action, 'actor' => $actors[$e->actor_id] ?? 'system',
                'credential' => isset($names[$e->entity_id]) ? "{$names[$e->entity_id]->credential_no} {$names[$e->entity_id]->system_name}" : '#'.$e->entity_id, 'credential_id' => $e->entity_id,
                'reason' => $e->reason, 'ip' => $e->ip])->values(),
        ]);
    }

    public function jml(Request $request): Response
    {
        $this->authorize('viewAny', JmlChecklist::class);

        return Inertia::render('Credentials/Jml', [
            'checklists' => JmlChecklist::query()->orderByRaw("status = 'open' desc")->orderByDesc('id')->limit(200)->get()->map(fn (JmlChecklist $c): array => ['id' => $c->id, 'kind' => $c->kind,
                'person' => $c->user_id ? User::query()->whereKey($c->user_id)->value('name') : ('staff #'.$c->staff_profile_id), 'trigger' => $c->trigger, 'items' => $c->items, 'status' => $c->status,
                'created_at' => $c->created_at?->toDateTimeString(), 'completed_at' => $c->completed_at?->toDateTimeString()])->values(),
            'can' => ['edit' => $this->user($request)->can('update', JmlChecklist::class)],
        ]);
    }

    public function tick(Request $request, JmlChecklist $checklist, JmlService $jml): RedirectResponse
    {
        $this->authorize('update', JmlChecklist::class);
        $data = $request->validate(['key' => ['required', 'string', 'max:60']]);

        return $this->attempt(fn () => $jml->tick($checklist, $data['key'], $this->user($request)), __('Checked.'));
    }

    /** @return array<string, mixed> */
    private function row(CredentialMetadata $c, User $user): array
    {
        return ['id' => $c->id, 'credential_no' => $c->credential_no, 'system_name' => $c->system_name, 'category' => $c->category, 'environment' => $c->environment, 'sensitivity' => $c->sensitivity,
            'status' => $c->status, 'owner' => User::query()->whereKey($c->owner_id)->value('name'), 'vault_provider' => $c->vault_provider, 'has_reference' => $c->vault_item_reference !== null,
            'rotation_due' => $c->rotation_due?->toDateString(), 'expiry_date' => $c->expiry_date?->toDateString(), 'is_shared_service_account' => $c->is_shared_service_account,
            'entitled' => $c->owner_id === $user->id || $c->hasActiveEntitlement($user)];
    }

    /** @return array<string, mixed> */
    private function requestRow(CredentialAccessRequest $r): array
    {
        return ['id' => $r->id, 'credential_id' => $r->credential_id, 'credential' => $r->credential->system_name, 'requester' => User::query()->whereKey($r->requester_id)->value('name'),
            'justification' => $r->justification, 'access_until' => $r->access_until?->toDateString(), 'status' => $r->status, 'created_at' => $r->created_at?->toDateTimeString(), 'decision_note' => $r->decision_note];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['system_name' => ['required', 'string', 'max:255'], 'category' => ['required', Rule::in(CredentialMetadata::CATEGORIES)], 'environment' => ['required', 'in:production,staging,development,shared'],
            'url' => ['nullable', 'url:https,http', 'max:500'], 'username_reference' => ['nullable', 'string', 'max:255'], 'owner_id' => ['required', 'integer', 'exists:users,id'],
            'project_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'], 'vault_provider' => ['required', 'in:none,bitwarden,onepassword,other'],
            'vault_item_reference' => ['nullable', 'string', 'max:255'], 'sensitivity' => ['required', 'in:low,medium,high,critical'], 'rotation_interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'last_rotated_on' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date'], 'is_shared_service_account' => ['boolean'], 'shared_justification' => ['nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
