<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Reports\ReportCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** View any catalog report the user may view (projects, HR, …); export goes through the Export Centre. @see SRS M11-05 */
final class CatalogReportController
{
    public function __invoke(Request $request, string $key, ReportCatalog $catalog, PermissionService $permissions): Response
    {
        $def = $catalog->find($key);
        abort_if($def === null, 404);
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::View, $def->resource), 403);
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'as_of' => ['nullable', 'date']]);
        $filters = ['from' => $f['from'] ?? today()->startOfYear()->toDateString(), 'to' => $f['to'] ?? today()->toDateString(), 'as_of' => $f['as_of'] ?? today()->toDateString()];

        return Inertia::render('Finance/Reports/Show', [
            'report' => $key, 'name' => $def->name, 'data' => $def->run(array_intersect_key($filters, array_flip($def->filters)), $user), 'filters' => $filters + ['basis' => null, 'account_id' => null], 'accounts' => [],
            'canExport' => $permissions->can($user, PermissionAction::Export, $def->resource) && $permissions->can($user, PermissionAction::Create, 'exports'),
            'routes' => ['run' => route('reports.view', $key), 'export' => route('reports.exports.index', ['report' => $key]), 'module' => $def->module, 'filters' => $def->filters],
        ]);
    }
}
