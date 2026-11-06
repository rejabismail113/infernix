<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Http\Controllers;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\HumanResources\Enums\TimesheetStatus;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\HumanResources\Models\Timesheet;
use App\Modules\HumanResources\Models\TimesheetEntry;
use App\Modules\HumanResources\Services\TimesheetService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Timesheets: staff see and submit their own (M04-02); PMs verify timesheets booked to projects they manage;
 * Finance calculates at the locked rate (M04-03). Entries only for assigned projects (UAT-07).
 *
 * @see SRS M04-02, M04-03, UAT-07, WF-05
 */
final class TimesheetController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly TimesheetService $service, private readonly PermissionService $permissions, private readonly ProjectDirectory $projects) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Timesheet::class);
        /** @var User $user */
        $user = $request->user();
        $me = StaffProfile::query()->where('user_id', $user->id)->first();
        $scope = $this->permissions->scopeFor($user, 'timesheets', PermissionAction::View);
        $managed = $this->projects->managedProjectIds($user);
        $base = Timesheet::query()->with('staff')->orderByDesc('period_start');
        $row = fn (Timesheet $t): array => self::row($t);

        return Inertia::render('HR/Timesheets/Index', [
            'mine' => $me ? (clone $base)->where('staff_profile_id', $me->id)->limit(100)->get()->map($row) : [],
            'toVerify' => (clone $base)->where('status', 'submitted')->where('staff_profile_id', '!=', $me->id ?? 0)
                ->when($this->permissions->scopeFor($user, 'timesheets', PermissionAction::Approve) !== RecordScope::All, fn ($q) => $q->whereIn('id', TimesheetEntry::query()->whereIn('project_id', $managed === [] ? [0] : $managed)->select('timesheet_id')))
                ->when(! $this->permissions->can($user, PermissionAction::Approve, 'timesheets'), fn ($q) => $q->whereRaw('false'))->limit(200)->get()->map($row),
            'toCalculate' => $this->permissions->can($user, PermissionAction::Pay, 'timesheets') ? (clone $base)->whereIn('status', ['verified', 'calculated'])->limit(200)->get()->map($row) : [],
            'all' => $scope === RecordScope::All ? (clone $base)->limit(200)->get()->map($row) : [],
            'me' => $me ? ['id' => $me->id, 'name' => $me->displayName()] : null,
            'can' => ['create' => $me !== null && $user->can('create', Timesheet::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Timesheet::class);
        $data = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start']]);
        $user = $this->user($request);
        $me = StaffProfile::query()->where('user_id', $user->id)->first();
        abort_if($me === null, 403, 'You have no staff profile.');

        return $this->attempt(function () use ($me, $data, $user): RedirectResponse {
            $t = $this->service->create($me, $data['period_start'], $data['period_end'], $user);

            return redirect()->route('hr.timesheets.show', $t->id);
        }, '');
    }

    public function show(Request $request, Timesheet $timesheet): Response
    {
        $this->authorize('view', $timesheet);
        $user = $this->user($request);
        $timesheet->load(['staff', 'entries']);
        $own = $timesheet->isOwnedBy($user);
        $projects = collect($this->projects->options($user))->keyBy('id');
        $assigned = $this->projects->assignedProjectIds($timesheet->staff_profile_id, $timesheet->period_end->toDateString());
        $snap = fn (int $id): string => isset($projects[$id]) ? $projects[$id]['label'] : (($s = $this->projects->snapshot($id)) !== null ? "{$s['code']} {$s['name']}" : '#'.$id);
        $editable = $own && in_array($timesheet->status, [TimesheetStatus::Draft, TimesheetStatus::Rejected], true);

        return Inertia::render('HR/Timesheets/Show', [
            'timesheet' => self::row($timesheet) + ['rejection_reason' => $timesheet->rejection_reason, 'rate_type' => $timesheet->rate_type],
            'entries' => $timesheet->entries->map(fn (TimesheetEntry $e): array => ['id' => $e->id, 'project_id' => $e->project_id, 'project' => $snap($e->project_id), 'work_date' => $e->work_date->toDateString(),
                'quantity' => $e->quantity, 'description' => $e->description, 'exception_granted' => $e->exception_granted])->values(),
            'projectOptions' => $editable ? collect($assigned)->map(fn (int $id): array => ['id' => $id, 'label' => $snap($id)])->values()
                ->concat($this->service->hasExceptionRole($user) ? collect($this->projects->options($user))->reject(fn ($p) => in_array($p['id'], $assigned, true))->map(fn ($p) => ['id' => $p['id'], 'label' => $p['label'].' (exception)'])->values() : [])->values() : [],
            'unit' => $timesheet->staff->engagementOn($timesheet->period_end)?->unitLabel() ?? 'hour',
            'can' => [
                'edit' => $editable, 'submit' => $editable,
                'verify' => $timesheet->status === TimesheetStatus::Submitted && ! $own && $user->can('approve', $timesheet),
                'calculate' => $timesheet->status === TimesheetStatus::Verified && $this->permissions->can($user, PermissionAction::Pay, 'timesheets'),
            ],
        ]);
    }

    public function entries(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($timesheet->isOwnedBy($user) && $user->can('update', $timesheet), 403);
        $data = $request->validate(['entries' => ['present', 'array', 'max:300'], 'entries.*.project_id' => ['required', 'integer', 'exists:projects,id'], 'entries.*.work_date' => ['required', 'date'],
            'entries.*.quantity' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'], 'entries.*.description' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->saveEntries($timesheet, array_values($data['entries']), $user), __('Timesheet saved.'));
    }

    public function submit(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($timesheet->isOwnedBy($user), 403);

        return $this->attempt(fn () => $this->service->submit($timesheet, $user), __('Submitted to your project manager.'));
    }

    public function verify(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorize('approve', $timesheet);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->verify($timesheet, $this->user($request), (bool) $data['approve'], $data['reason'] ?? null), $data['approve'] ? __('Verified.') : __('Returned to the staff member.'));
    }

    public function calculate(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorize('pay', $timesheet);

        return $this->attempt(fn () => $this->service->calculate($timesheet, $this->user($request)), __('Payment calculated at the locked rate.'));
    }

    /** @return array<string, mixed> */
    public static function row(Timesheet $t): array
    {
        return ['id' => $t->id, 'staff' => $t->staff->displayName(), 'staff_no' => $t->staff->staff_no, 'period_start' => $t->period_start->toDateString(), 'period_end' => $t->period_end->toDateString(),
            'status' => $t->status->value, 'total_quantity' => $t->total_quantity, 'rate' => $t->rate_snapshot !== null ? (string) $t->rate_snapshot->getAmount() : null,
            'amount' => $t->calculated_amount !== null ? (string) $t->calculated_amount->getAmount() : null, 'classification' => $t->staff->classification];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
