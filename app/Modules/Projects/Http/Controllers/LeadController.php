<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Lead;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Lead / opportunity pipeline (M02-02). PMs see their own leads. @see SRS M02-02, WF-04 */
final class LeadController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly ProjectService $service, private readonly PermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Lead::class);
        /** @var User $user */
        $user = $request->user();
        $scope = $this->permissions->scopeFor($user, 'leads', PermissionAction::View);

        return Inertia::render('Projects/Leads/Index', [
            'leads' => Lead::query()->with('client')->when($scope !== RecordScope::All, fn ($q) => $q->where('owner_user_id', $user->id))->orderByDesc('id')->limit(500)->get()
                ->map(fn (Lead $l): array => [
                    'id' => $l->id, 'title' => $l->title, 'party' => $l->partyName(), 'client_id' => $l->client_id, 'prospect_name' => $l->prospect_name, 'stage' => $l->stage,
                    'engagement_type' => $l->engagement_type, 'estimated_value' => (string) $l->estimated_value->getAmount(), 'expected_close' => $l->expected_close?->toDateString(),
                    'source' => $l->source, 'notes' => $l->notes, 'quotation_id' => $l->quotation_id, 'lost_reason' => $l->lost_reason,
                    'owner' => User::query()->whereKey($l->owner_user_id)->value('name'),
                ]),
            'stages' => Lead::STAGES,
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn (Client $c): array => ['id' => $c->id, 'label' => $c->name]),
            'can' => ['create' => $user->can('create', Lead::class), 'quote' => $this->permissions->can($user, PermissionAction::Create, 'quotations'), 'project' => $this->permissions->can($user, PermissionAction::Create, 'projects')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Lead::class);

        return $this->attempt(fn () => $this->service->saveLead($this->validated($request), $this->user($request)), __('Lead added.'));
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);

        return $this->attempt(fn () => $this->service->saveLead($this->validated($request), $this->user($request), $lead), __('Lead updated.'));
    }

    public function stage(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);
        $data = $request->validate(['stage' => ['required', 'in:'.implode(',', Lead::STAGES)], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->moveLead($lead, $data['stage'], $this->user($request), $data['reason'] ?? null), __('Lead moved to :s.', ['s' => $data['stage']]));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'], 'prospect_name' => ['nullable', 'string', 'max:190'], 'title' => ['required', 'string', 'max:190'],
            'engagement_type' => ['required', 'in:project,training,retainer'], 'estimated_value' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'expected_close' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
