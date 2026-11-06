<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Reporting\Services\ProjectFinancials;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Project financials & document chain (M02-02, M02-05). Same record scope as the project itself (M02-07). */
final class ProjectFinancialsController
{
    public function __invoke(Request $request, string $project, ProjectFinancials $financials, PermissionService $permissions): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::View, 'projects'), 403);
        try {
            $data = $financials->for((int) $project, $user);
        } catch (AuthorizationException) {
            abort(403);
        }

        return Inertia::render('Reporting/ProjectFinancials', [
            'data' => $data,
            'can' => ['journals' => $permissions->can($user, PermissionAction::View, 'journals'), 'invoices' => $permissions->can($user, PermissionAction::View, 'invoices'),
                'quotations' => $permissions->can($user, PermissionAction::View, 'quotations'), 'receipts' => $permissions->can($user, PermissionAction::View, 'receipts'),
                'client_pos' => $permissions->can($user, PermissionAction::View, 'client_pos'), 'leads' => $permissions->can($user, PermissionAction::View, 'leads')],
        ]);
    }
}
