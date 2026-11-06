<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Every report the user may view, grouped by module (row scope applied inside each report). @see SRS M11-05 */
final class ReportIndexController
{
    public function __invoke(Request $request, ReportCatalog $catalog, PermissionService $permissions): Response
    {
        /** @var User $user */
        $user = $request->user();
        $reports = array_values(array_filter($catalog->all(), fn (ReportDefinition $r): bool => $permissions->can($user, PermissionAction::View, $r->resource)));
        abort_if($reports === [], 403);

        return Inertia::render('Reporting/Index', [
            'reports' => array_map(fn (ReportDefinition $r): array => ['key' => $r->key, 'name' => $r->name, 'module' => $r->module,
                'href' => str_starts_with($r->key, 'finance.') && $r->key !== 'finance.reconciliation-status' ? route('finance.reports.show', substr($r->key, 8))
                    : (str_starts_with($r->key, 'commercial.') ? route('commercial.reports.show', substr($r->key, 11)) : route('reports.view', $r->key))], $reports),
            'canExport' => $permissions->can($user, PermissionAction::Create, 'exports'),
        ]);
    }
}
