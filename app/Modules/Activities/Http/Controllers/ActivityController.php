<?php

declare(strict_types=1);

namespace App\Modules\Activities\Http\Controllers;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Models\ActivityAttendee;
use App\Modules\Activities\Models\ActivityItineraryItem;
use App\Modules\Activities\Services\ActivityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activities & travel (M07): register, itinerary, attendees, linked costs (budget vs actual), post-event report
 * and photos (Document Centre).
 *
 * @see SRS M07-01, M07-02, M07-03
 */
final class ActivityController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly ActivityService $service) {}

    public function index(Request $request, ProjectDirectory $projects, ClientDirectory $clients): Response
    {
        $this->authorize('viewAny', Activity::class);
        $user = $this->user($request);
        $f = $request->only(['q', 'type', 'status']);
        $rows = $this->service->visibleQuery($user)->withCount('attendees')
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($w) => $w->where('title', 'ilike', '%'.$f['q'].'%')->orWhere('activity_no', 'ilike', '%'.$f['q'].'%')))
            ->when(! empty($f['type']), fn ($q) => $q->where('type', $f['type']))->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('start_date')->limit(300)->get();
        $canCreate = $user->can('create', Activity::class);

        return Inertia::render('Activities/Index', [
            'activities' => $rows->map(fn (Activity $a): array => $this->row($a) + ['attendees' => $a->attendees_count])->values(),
            'types' => Activity::TYPES, 'filters' => $f,
            'projects' => $canCreate ? $projects->options($user) : [], 'clients' => $canCreate ? $clients->options($user) : [],
            'can' => ['create' => $canCreate],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Activity::class);
        $data = $request->validate($this->rules());

        return $this->attempt(fn () => redirect()->route('activities.activities.show', $this->service->create($data, $this->user($request))->id), __('Activity created.'));
    }

    public function show(Request $request, Activity $activity, DocumentStore $documents, FinanceRecords $finance, StaffDirectory $staff, ProjectDirectory $projects, ClientDirectory $clients, PermissionService $permissions): Response
    {
        $this->authorize('view', $activity);
        $user = $this->user($request);
        $activity->load('attendees', 'itinerary');
        $canEdit = $user->can('update', $activity);

        return Inertia::render('Activities/Show', [
            'activity' => $this->row($activity) + ['purpose' => $activity->purpose, 'project_id' => $activity->project_id, 'client_id' => $activity->client_id, 'organizer_id' => $activity->organizer_id,
                'report' => $activity->report, 'report_submitted_at' => $activity->report_submitted_at?->toDateTimeString(),
                'project' => $activity->project_id ? $projects->snapshot($activity->project_id)['name'] ?? null : null, 'client' => $activity->client_id ? $clients->snapshot($activity->client_id)['name'] ?? null : null],
            'attendees' => $activity->attendees->map(fn (ActivityAttendee $x): array => ['staff_profile_id' => $x->staff_profile_id, 'name' => $x->name, 'role' => $x->role])->values(),
            'itinerary' => $activity->itinerary->map(fn (ActivityItineraryItem $i): array => ['item_date' => $i->item_date->toDateString(), 'kind' => $i->kind, 'description' => $i->description, 'estimated_cost' => (string) $i->estimated_cost->getAmount()])->values(),
            'costs' => $finance->activityLines($activity->id),
            'summary' => $this->service->budgetVsActual($activity->id),
            'documents' => $documents->linkedTo('activity', $activity->id),
            'staff' => $canEdit ? $staff->options($user) : [],
            'projects' => $canEdit ? $projects->options($user) : [], 'clients' => $canEdit ? $clients->options($user) : [],
            'types' => Activity::TYPES,
            'can' => ['edit' => $canEdit, 'link_costs' => $canEdit && ($permissions->can($user, PermissionAction::View, 'transactions') || $permissions->can($user, PermissionAction::View, 'claims'))],
        ]);
    }

    public function update(Request $request, Activity $activity): RedirectResponse
    {
        $this->authorize('update', $activity);
        $data = $request->validate($this->rules());

        return $this->attempt(fn () => $this->service->update($activity, $data, $this->user($request)), __('Saved.'));
    }

    public function status(Request $request, Activity $activity): RedirectResponse
    {
        $this->authorize('update', $activity);
        $data = $request->validate(['status' => ['required', 'in:planned,ongoing,completed,cancelled']]);

        return $this->attempt(fn () => $this->service->setStatus($activity, $data['status'], $this->user($request)), __('Status updated.'));
    }

    public function attendees(Request $request, Activity $activity): RedirectResponse
    {
        $this->authorize('update', $activity);
        $data = $request->validate(['attendees' => ['present', 'array', 'max:200'], 'attendees.*.staff_profile_id' => ['nullable', 'integer'], 'attendees.*.name' => ['nullable', 'string', 'max:255'], 'attendees.*.role' => ['nullable', 'string', 'max:40']]);

        return $this->attempt(fn () => $this->service->saveAttendees($activity, $data['attendees'], $this->user($request)), __('Attendees saved.'));
    }

    public function itinerary(Request $request, Activity $activity): RedirectResponse
    {
        $this->authorize('update', $activity);
        $data = $request->validate(['items' => ['present', 'array', 'max:200'], 'items.*.item_date' => ['required', 'date'], 'items.*.kind' => ['required', 'in:travel,mileage,hotel,meal,transport,session,other'],
            'items.*.description' => ['required', 'string', 'max:1000'], 'items.*.estimated_cost' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/']]);

        return $this->attempt(fn () => $this->service->saveItinerary($activity, $data['items'], $this->user($request)), __('Itinerary saved.'));
    }

    public function report(Request $request, Activity $activity): RedirectResponse
    {
        $this->authorize('update', $activity);
        $data = $request->validate(['report' => ['required', 'string', 'max:20000']]);

        return $this->attempt(fn () => $this->service->submitReport($activity, $data['report'], $this->user($request)), __('Report saved.'));
    }

    /** Booked finance lines that may be tagged (needs finance visibility). */
    public function candidates(Request $request, Activity $activity, FinanceRecords $finance, PermissionService $permissions): JsonResponse
    {
        $this->authorize('update', $activity);
        $user = $this->user($request);
        abort_unless($permissions->can($user, PermissionAction::View, 'transactions') || $permissions->can($user, PermissionAction::View, 'claims'), 403);

        return response()->json(['lines' => $finance->taggableLines((string) $request->query('q', ''), 25)]);
    }

    public function linkCost(Request $request, Activity $activity, PermissionService $permissions): RedirectResponse
    {
        $this->authorize('update', $activity);
        abort_unless($permissions->can($this->user($request), PermissionAction::View, 'transactions') || $permissions->can($this->user($request), PermissionAction::View, 'claims'), 403);
        $data = $request->validate(['line_type' => ['required', 'in:transaction_line,claim_line,bill_line'], 'line_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $this->service->linkCost($activity, $data['line_type'], (int) $data['line_id'], $this->user($request)), __('Cost linked (not copied).'));
    }

    public function unlinkCost(Request $request, Activity $activity, PermissionService $permissions): RedirectResponse
    {
        $this->authorize('update', $activity);
        abort_unless($permissions->can($this->user($request), PermissionAction::View, 'transactions') || $permissions->can($this->user($request), PermissionAction::View, 'claims'), 403);
        $data = $request->validate(['line_type' => ['required', 'in:transaction_line,claim_line,bill_line'], 'line_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $this->service->unlinkCost($activity, $data['line_type'], (int) $data['line_id'], $this->user($request)), __('Cost unlinked.'));
    }

    /** @return array<string, mixed> */
    private function row(Activity $a): array
    {
        return ['id' => $a->id, 'activity_no' => $a->activity_no, 'type' => $a->type, 'title' => $a->title, 'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date->toDateString(),
            'location' => $a->location, 'status' => $a->status, 'budget' => (string) $a->budget->getAmount()];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['type' => ['required', Rule::in(Activity::TYPES)], 'title' => ['required', 'string', 'max:255'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'location' => ['nullable', 'string', 'max:255'], 'purpose' => ['nullable', 'string', 'max:5000'], 'project_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'],
            'budget' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'], 'organizer_id' => ['nullable', 'integer', 'exists:users,id']];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
