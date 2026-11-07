<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Projects\Enums\LeadLostReason;
use App\Modules\Projects\Enums\LeadType;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Lead;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lead / opportunity pipeline (M02-02). PMs see their own leads. Registering a new client from the lead form also
 * needs the clients:create permission. @see SRS M02-02, WF-04
 */
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

        $leads = Lead::query()->with('client')->when($scope !== RecordScope::All, fn ($q) => $q->where('owner_user_id', $user->id))->orderByDesc('id')->limit(500)->get();
        $owners = User::query()->whereIn('id', $leads->pluck('owner_user_id')->unique()->all())->pluck('name', 'id');

        return Inertia::render('Projects/Leads/Index', [
            'leads' => $leads->map(fn (Lead $l): array => [
                'id' => $l->id, 'title' => $l->title, 'party' => $l->partyName(), 'client_id' => $l->client_id, 'prospect_name' => $l->prospect_name, 'stage' => $l->stage,
                'engagement_type' => $l->engagement_type, 'engagement_type_other' => $l->engagement_type_other,
                'type_label' => $l->engagement_type === 'other' && $l->engagement_type_other ? $l->engagement_type_other : (LeadType::tryFrom($l->engagement_type)?->label() ?? $l->engagement_type),
                'estimated_value' => (string) $l->estimated_value->getAmount(), 'expected_close' => $l->expected_close?->toDateString(),
                'next_follow_up_on' => $l->next_follow_up_on?->toDateString(), 'source' => $l->source, 'source_other' => $l->source_other,
                'contact_person' => $l->contact_person, 'contact_email' => $l->contact_email, 'contact_phone' => $l->contact_phone,
                'notes' => $l->notes, 'quotation_id' => $l->quotation_id, 'lost_reason' => $l->lost_reason, 'lost_reason_code' => $l->lost_reason_code,
                'owner' => $owners[$l->owner_user_id] ?? null,
            ]),
            'stages' => Lead::STAGES,
            'stageDescriptions' => Lead::STAGE_DESCRIPTIONS,
            'types' => LeadType::options(),
            'sources' => Lead::SOURCES,
            'lostReasons' => LeadLostReason::options(),
            'clients' => Client::query()->with('contacts')->whereIn('status', ['active', 'prospect'])->orderBy('name')->get()
                ->map(function (Client $c): array {
                    $primary = $c->contacts->first(); // primary first (see Client::contacts())

                    return ['id' => $c->id, 'label' => $c->name, 'contact_person' => $primary->name ?? $c->contact_person,
                        'email' => $primary?->email ?: $c->email, 'phone' => $primary?->phone ?: $c->phone];
                }),
            'can' => [
                'create' => $user->can('create', Lead::class), 'createClient' => $user->can('create', Client::class),
                'quote' => $this->permissions->can($user, PermissionAction::Create, 'quotations'), 'project' => $this->permissions->can($user, PermissionAction::Create, 'projects'),
                'export' => $this->permissions->can($user, PermissionAction::Export, 'leads') && $this->permissions->can($user, PermissionAction::Create, 'exports'),
            ],
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

        return $this->attempt(fn () => $this->service->saveLead($this->validated($request, $lead), $this->user($request), $lead), __('Lead updated.'));
    }

    public function stage(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('update', $lead);
        $data = $request->validate([
            'stage' => ['required', 'in:'.implode(',', Lead::STAGES)], 'reason' => ['nullable', 'string', 'max:500'],
            'reason_code' => ['nullable', Rule::enum(LeadLostReason::class)],
        ]);

        return $this->attempt(fn () => $this->service->moveLead($lead, $data['stage'], $this->user($request), $data['reason'] ?? null, $data['reason_code'] ?? null), __('Lead moved to :s.', ['s' => $data['stage']]));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Lead $lead = null): array
    {
        $data = $request->validate([
            'party' => ['nullable', 'in:existing,prospect,new_client'],
            'client_id' => ['nullable', 'required_if:party,existing', 'integer', 'exists:clients,id'],
            'prospect_name' => ['nullable', 'required_if:party,prospect', 'string', 'max:190'],
            'new_client.name' => ['nullable', 'required_if:party,new_client', 'string', 'max:190'],
            'title' => ['required', 'string', 'max:190'],
            'engagement_type' => ['required', Rule::enum(LeadType::class)],
            'engagement_type_other' => ['nullable', 'required_if:engagement_type,other', 'string', 'max:120'],
            'estimated_value' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'expected_close' => ['nullable', 'date'], 'next_follow_up_on' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(Lead::SOURCES)], 'source_other' => ['nullable', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:190'], 'contact_email' => ['nullable', 'email', 'max:190'], 'contact_phone' => ['nullable', 'string', 'max:40'],
            'stage' => ['nullable', 'in:'.implode(',', Lead::STAGES)],
            'lost_reason_code' => ['nullable', Rule::enum(LeadLostReason::class)],
            'lost_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'engagement_type_other.required_if' => __('Specify the opportunity type.'),
            'new_client.name.required_if' => __('Enter the new client\'s name.'),
        ]);
        if (($data['stage'] ?? null) === 'lost' && $lead?->stage !== 'lost') {
            if (empty($data['lost_reason_code'])) {
                throw ValidationException::withMessages(['lost_reason_code' => __('Choose why the lead was lost.')]);
            }
            if ($data['lost_reason_code'] === 'other' && trim((string) ($data['lost_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['lost_reason' => __('Explain why the lead was lost.')]);
            }
        }
        $party = $data['party'] ?? null;
        if ($party === 'new_client') {
            $this->authorize('create', Client::class);
            $data['client_id'] = null;
        } else {
            unset($data['new_client']);
        }
        if ($party === 'prospect') {
            $data['client_id'] = null;
        }

        return $data;
    }
}
