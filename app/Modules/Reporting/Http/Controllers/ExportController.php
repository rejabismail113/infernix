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
            'history' => Export::query()->where('user_id', $user->id)->orderByDesc('id')->limit(25)->get()->map(fn (Export $e): array => [
                'id' => $e->id, 'report' => $this->catalog->find($e->report_key)->name ?? $e->report_key, 'format' => $e->format, 'filters' => $e->filters,
                'rows' => $e->row_count, 'created_at' => $e->created_at->toIso8601String(), 'download' => $e->document_id !== null ? route('documents.download', ['document' => $e->document_id]) : null,
            ]),
            'defaults' => ['from' => today()->startOfYear()->toDateString(), 'to' => today()->toDateString(), 'as_of' => today()->toDateString()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'report' => ['required', 'string', 'max:80'], 'format' => ['required', 'in:csv,xlsx'],
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
