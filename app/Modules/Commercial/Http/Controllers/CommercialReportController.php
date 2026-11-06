<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Reports\ReportCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Quotation register, invoice register, issuance history (view; export via the Export Centre). @see SRS M03 outputs, M11-05 */
final class CommercialReportController
{
    public const REPORTS = ['quotation-register' => 'Quotation register', 'invoice-register' => 'Invoice register', 'issuance-history' => 'Document issuance history'];

    public function __invoke(Request $request, string $report, ReportCatalog $catalog, PermissionService $permissions): Response
    {
        $def = $catalog->find('commercial.'.$report);
        abort_if($def === null, 404);
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::View, $def->resource), 403);
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $filters = ['from' => $f['from'] ?? today()->startOfYear()->toDateString(), 'to' => $f['to'] ?? today()->toDateString()];

        return Inertia::render('Finance/Reports/Show', [
            'report' => $report, 'name' => $def->name, 'data' => $def->run($filters, $user), 'filters' => $filters + ['as_of' => null, 'basis' => null, 'account_id' => null], 'accounts' => [],
            'canExport' => $permissions->can($user, PermissionAction::Export, $def->resource),
            'routes' => ['run' => route('commercial.reports.show', $report), 'export' => route('reports.exports.index', ['report' => 'commercial.'.$report]), 'module' => 'Commercial', 'filters' => $def->filters],
        ]);
    }
}
