<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Project stubs created from accepted quotations (M02 completes this in Prompt 4). @see SRS M02-01, M03-02, UAT-06 */
final class ProjectController
{
    use AuthorizesRequests;

    public function __construct(private readonly PermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);
        /** @var User $user */
        $user = $request->user();
        $scope = $this->permissions->scopeFor($user, 'projects', PermissionAction::View);

        return Inertia::render('Projects/Projects/Index', [
            'projects' => Project::query()->with('client')->when($scope !== RecordScope::All, fn ($q) => $q->where(fn ($w) => $w->where('pm_user_id', $user->id)->orWhere('created_by', $user->id)))
                ->orderByDesc('id')->limit(500)->get()->map(fn (Project $p): array => self::row($p)),
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Projects/Projects/Show', [
            'project' => self::row($project->load('client')) + ['scope' => $project->scope],
            'can' => [
                'invoice' => $this->permissions->can($user, PermissionAction::Create, 'invoices'),
                'quotation' => $this->permissions->can($user, PermissionAction::View, 'quotations'),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(Project $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'client' => $p->client->name, 'client_id' => $p->client_id, 'engagement_type' => $p->engagement_type,
            'contract_value' => (string) $p->contract_value->getAmount(), 'status_delivery' => $p->status_delivery, 'status_payment' => $p->status_payment,
            'source_quotation_id' => $p->source_quotation_id,
        ];
    }
}
