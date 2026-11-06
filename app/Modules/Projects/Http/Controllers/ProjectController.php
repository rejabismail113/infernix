<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Lead;
use App\Modules\Projects\Models\Milestone;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectMember;
use App\Modules\Projects\Models\Task;
use App\Modules\Projects\Models\TrainingSession;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Project list & profile: scope, team, milestones/tasks, training sessions, documents, closeout. PMs see and edit
 * only assigned projects (record scope "assigned", M02-07); every action is policy-checked.
 *
 * @see SRS M02-01, M02-03, M02-04, M02-06, M02-07
 */
final class ProjectController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly PermissionService $permissions, private readonly ProjectService $service, private readonly StaffDirectory $staff) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);
        /** @var User $user */
        $user = $request->user();
        $scope = $this->permissions->scopeFor($user, 'projects', PermissionAction::View);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:lead,active,on_hold,completed,closed']]);

        return Inertia::render('Projects/Projects/Index', [
            'projects' => Project::query()->with('client')->when($scope !== RecordScope::All, fn ($q) => $q->whereIn('id', Project::assignedIds($user)))
                ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status_delivery', $v))
                ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$v}%")->orWhere('code', 'ilike', "%{$v}%")))
                ->orderByDesc('id')->limit(500)->get()->map(fn (Project $p): array => self::row($p)),
            'filters' => $f,
            'clients' => $user->can('create', Project::class) ? Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn (Client $c): array => ['id' => $c->id, 'label' => $c->name]) : [],
            'leads' => $user->can('create', Project::class) ? Lead::query()->whereNotIn('stage', ['won', 'lost'])->orderByDesc('id')->limit(200)->get()->map(fn (Lead $l): array => ['id' => $l->id, 'label' => $l->title, 'client_id' => $l->client_id]) : [],
            'can' => ['create' => $user->can('create', Project::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);
        $data = $this->validated($request) + $request->validate(['lead_id' => ['nullable', 'integer', 'exists:leads,id']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($data, $user): RedirectResponse {
            $p = $this->service->create($data, $user);

            return redirect()->route('projects.projects.show', $p->id)->with('success', __('Project :code created.', ['code' => $p->code]));
        }, '');
    }

    public function show(Request $request, Project $project, DocumentStore $documents): Response
    {
        $this->authorize('view', $project);
        /** @var User $user */
        $user = $request->user();
        $project->load(['client', 'members', 'milestones.tasks', 'trainingSessions']);
        $staffName = fn (?int $id): ?string => $id === null ? null : ($this->staff->snapshot($id)['name'] ?? null);
        $edit = $user->can('update', $project);

        return Inertia::render('Projects/Projects/Show', [
            'project' => self::row($project) + [
                'scope' => $project->scope, 'description' => $project->description, 'billing_terms' => $project->billing_terms, 'starts_on' => $project->starts_on?->toDateString(),
                'ends_on' => $project->ends_on?->toDateString(), 'cost_budget' => $project->cost_budget !== null ? (string) $project->cost_budget->getAmount() : null,
                'pm_user_id' => $project->pm_user_id, 'pm' => $project->pm_user_id !== null ? User::query()->whereKey($project->pm_user_id)->value('name') : null,
                'closeout' => $project->closeout_checklist ?? [], 'lead_id' => $project->lead_id,
            ],
            'closeoutItems' => Project::CLOSEOUT_ITEMS,
            'members' => $project->members->map(fn (ProjectMember $m): array => ['id' => $m->id, 'staff_profile_id' => $m->staff_profile_id, 'name' => $staffName($m->staff_profile_id), 'role' => $m->role,
                'from_date' => $m->from_date->toDateString(), 'to_date' => $m->to_date?->toDateString(), 'engagement_id' => $m->engagement_id])->values(),
            'milestones' => $project->milestones->map(fn (Milestone $m): array => [
                'id' => $m->id, 'title' => $m->title, 'deliverable' => $m->deliverable, 'due_on' => $m->due_on?->toDateString(), 'status' => $m->status, 'blocker' => $m->blocker,
                'billable_amount' => $m->billable_amount !== null ? (string) $m->billable_amount->getAmount() : null, 'overdue' => $m->isOverdue(), 'completed_at' => $m->completed_at?->toIso8601String(),
                'tasks' => $m->tasks->map(fn (Task $t): array => ['id' => $t->id, 'title' => $t->title, 'status' => $t->status, 'due_on' => $t->due_on?->toDateString(), 'assignee_staff_id' => $t->assignee_staff_id, 'assignee' => $staffName($t->assignee_staff_id)])->values(),
            ])->values(),
            'sessions' => $project->trainingSessions->map(fn (TrainingSession $s): array => ['id' => $s->id, 'course_title' => $s->course_title, 'session_date' => $s->session_date->toDateString(), 'venue' => $s->venue,
                'status' => $s->status, 'trainer' => $staffName($s->trainer_staff_id), 'participants' => $s->participants()->count(), 'feedback_score' => $s->feedback_score])->values(),
            'documents' => $documents->linkedTo('project', $project->id),
            'staffOptions' => $edit ? $this->staff->options($user) : [],
            'pmOptions' => $edit ? User::query()->whereHas('roles', fn ($r) => $r->whereIn('code', ['PM', 'OWN', 'FIN']))->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'label' => $u->name]) : [],
            'can' => [
                'edit' => $edit,
                'invoice' => $this->permissions->can($user, PermissionAction::Create, 'invoices'),
                'quotation' => $this->permissions->can($user, PermissionAction::View, 'quotations'),
                'financials' => true,
                'timesheet' => $this->permissions->can($user, PermissionAction::Create, 'timesheets'),
            ],
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $this->validated($request);

        return $this->attempt(fn () => $this->service->update($project, $data, $this->user($request)), __('Project updated.'));
    }

    public function status(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['status' => ['required', 'in:lead,active,on_hold,completed,closed'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->setDeliveryStatus($project, $data['status'], $this->user($request), $data['note'] ?? null), __('Delivery status updated.'));
    }

    public function closeout(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['items' => ['array'], 'items.*' => ['boolean']]);

        return $this->attempt(fn () => $this->service->saveCloseout($project, $data['items'] ?? [], $this->user($request)), __('Closeout checklist saved.'));
    }

    public function addMember(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);
        $data = $request->validate(['staff_profile_id' => ['required', 'integer', 'exists:staff_profiles,id'], 'role' => ['required', 'in:pm,lead,member,trainer'], 'from_date' => ['required', 'date'], 'to_date' => ['nullable', 'date', 'after_or_equal:from_date']]);

        return $this->attempt(fn () => $this->service->addMember($project, $data, $this->user($request)), __('Team member added.'));
    }

    public function endMember(Request $request, ProjectMember $member): RedirectResponse
    {
        $this->authorize('update', Project::query()->findOrFail($member->project_id));
        $data = $request->validate(['to_date' => ['required', 'date', 'after_or_equal:'.$member->from_date->toDateString()]]);

        return $this->attempt(fn () => $this->service->endMember($member, $data['to_date'], $this->user($request)), __('Assignment ended.'));
    }

    public function storeMilestone(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        return $this->attempt(fn () => $this->service->saveMilestone($project, $this->milestoneData($request), $this->user($request)), __('Milestone added.'));
    }

    public function updateMilestone(Request $request, Milestone $milestone): RedirectResponse
    {
        $this->authorize('update', $milestone->project);

        return $this->attempt(fn () => $this->service->saveMilestone($milestone->project, $this->milestoneData($request), $this->user($request), $milestone), __('Milestone updated.'));
    }

    public function storeTask(Request $request, Milestone $milestone): RedirectResponse
    {
        $this->authorize('update', $milestone->project);

        return $this->attempt(fn () => $this->service->saveTask($milestone, $this->taskData($request), $this->user($request)), __('Task added.'));
    }

    public function updateTask(Request $request, Task $task): RedirectResponse
    {
        $milestone = Milestone::query()->with('project')->findOrFail($task->milestone_id);
        $this->authorize('update', $milestone->project);

        return $this->attempt(fn () => $this->service->saveTask($milestone, $this->taskData($request), $this->user($request), $task), __('Task updated.'));
    }

    /** @return array<string, mixed> */
    public static function row(Project $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'client' => $p->client->name, 'client_id' => $p->client_id, 'engagement_type' => $p->engagement_type,
            'contract_value' => (string) $p->contract_value->getAmount(), 'status_delivery' => $p->status_delivery, 'status_payment' => $p->status_payment,
            'source_quotation_id' => $p->source_quotation_id,
        ];
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
            'client_id' => ['required', 'integer', 'exists:clients,id'], 'name' => ['required', 'string', 'max:190'], 'engagement_type' => ['required', Rule::in(['project', 'training', 'retainer'])],
            'scope' => ['nullable', 'string', 'max:10000'], 'description' => ['nullable', 'string', 'max:4000'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'contract_value' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'cost_budget' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'billing_terms' => ['nullable', 'string', 'max:2000'], 'pm_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }

    /** @return array<string, mixed> */
    private function milestoneData(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:190'], 'deliverable' => ['nullable', 'string', 'max:2000'], 'due_on' => ['nullable', 'date'],
            'status' => ['required', 'in:pending,in_progress,blocked,done'], 'blocker' => ['nullable', 'string', 'max:1000'], 'billable_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/']]);
    }

    /** @return array<string, mixed> */
    private function taskData(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:190'], 'assignee_staff_id' => ['nullable', 'integer', 'exists:staff_profiles,id'], 'due_on' => ['nullable', 'date'], 'status' => ['required', 'in:todo,doing,done']]);
    }
}
