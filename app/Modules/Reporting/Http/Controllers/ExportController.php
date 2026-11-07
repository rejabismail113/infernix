<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Modules\Reporting\Models\Export;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Services\ExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Export Centre: only reports the user may export are listed; the request re-checks permission server-side,
 * the file is stored in the Document Centre and downloaded through the signed, policy-checked route.
 *
 * @see SRS M11-05, §8.1, UAT-08
 */
final class ExportController
{
    use HandlesDomainErrors;

    public function __construct(private readonly ReportCatalog $catalog, private readonly ExportService $exports, private readonly PermissionService $permissions) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->permissions->can($user, PermissionAction::Create, 'exports'), 403);
        $reports = $this->catalog->exportableBy($user);
        abort_if($reports === [], 403);

        return Inertia::render('Reporting/ExportCentre', [
            'reports' => array_map(fn (ReportDefinition $r): array => ['key' => $r->key, 'name' => $r->name, 'module' => $r->module, 'filters' => $r->filters], $reports),
            'preselect' => $request->string('report')->toString() ?: null,
            'preselectFormat' => in_array($request->query('format'), ['csv', 'xlsx', 'pdf'], true) ? $request->query('format') : null,
            'history' => Export::query()->where('user_id', $user->id)->orderByDesc('id')->limit(25)->get()->map(fn (Export $e): array => [
                'id' => $e->id, 'report' => $this->catalog->find($e->report_key)->name ?? $e->report_key, 'format' => $e->format, 'filters' => $e->filters,
                'rows' => $e->row_count, 'created_at' => $e->created_at->toIso8601String(), 'download' => $e->document_id !== null ? route('documents.download', ['document' => $e->document_id]) : null,
            ]),
            'defaults' => ['from' => today()->startOfYear()->toDateString(), 'to' => today()->toDateString(), 'as_of' => today()->toDateString()],
            'schedules' => ReportSchedule::query()->where(fn ($q) => $q->where('created_by', $user->id)->orWhereJsonContains('recipient_ids', $user->id))->orderBy('id')->get()->map(fn (ReportSchedule $s): array => [
                'id' => $s->id, 'report' => $this->catalog->find($s->report_key)->name ?? $s->report_key, 'report_key' => $s->report_key, 'format' => $s->format, 'frequency' => $s->frequency, 'day' => $s->day,
                'recipients' => User::query()->whereIn('id', $s->recipient_ids)->pluck('name')->all(), 'active' => $s->active, 'last_run_at' => $s->last_run_at?->toIso8601String(), 'mine' => $s->created_by === $user->id]),
            'users' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'label' => $u->name]),
        ]);
    }

    /** Create a scheduled email. Recipients are re-authorised at every send (ScheduledReportService). */
    public function storeSchedule(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['report' => ['required', 'string', 'max:80'], 'format' => ['required', 'in:csv,xlsx,pdf'], 'frequency' => ['required', 'in:daily,weekly,monthly'],
            'day' => ['nullable', 'integer', 'min:1', 'max:31'], 'recipient_ids' => ['required', 'array', 'min:1', 'max:20'], 'recipient_ids.*' => ['integer', 'exists:users,id']]);
        $def = $this->catalog->find($data['report']);
        abort_if($def === null, 404);
        abort_unless($this->permissions->can($user, PermissionAction::Export, $def->resource) && $this->permissions->can($user, PermissionAction::Create, 'exports'), 403);
        ReportSchedule::query()->create(['report_key' => $def->key, 'format' => $data['format'], 'frequency' => $data['frequency'], 'day' => $data['day'] ?? null,
            'recipient_ids' => array_values(array_unique(array_map('intval', $data['recipient_ids']))), 'active' => true, 'created_by' => $user->id]);

        return back()->with('success', __('Schedule saved. Each recipient receives only what they are allowed to export.'));
    }

    public function toggleSchedule(Request $request, ReportSchedule $schedule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($schedule->created_by === $user->id || $user->isOwner(), 403);
        $schedule->forceFill(['active' => ! $schedule->active])->save();

        return back()->with('success', $schedule->active ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'report' => ['required', 'string', 'max:80'], 'format' => ['required', 'in:csv,xlsx,pdf'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'as_of' => ['nullable', 'date'], 'basis' => ['nullable', 'in:accrual,cash'], 'account_id' => ['nullable', 'integer', 'exists:chart_accounts,id'],
        ]);
        $def = $this->catalog->find($data['report']);
        abort_if($def === null, 404);
        abort_unless($this->permissions->can($user, PermissionAction::Export, $def->resource) && $this->permissions->can($user, PermissionAction::Create, 'exports'), 403);

        return $this->attempt(function () use ($user, $data): RedirectResponse {
            $export = $this->exports->export($user, $data['report'], $data['format'], array_filter($data, fn ($v, $k) => ! in_array($k, ['report', 'format'], true) && $v !== null, ARRAY_FILTER_USE_BOTH));

            return redirect()->route('documents.download', ['document' => $export->document_id]);
        }, '');
    }
}
