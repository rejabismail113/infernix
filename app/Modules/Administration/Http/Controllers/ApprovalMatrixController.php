<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Approval\ApprovalMatrix;
use App\Kernel\Approval\ApprovalMatrixRule;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Administration\Services\ApprovalMatrixService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approval matrix editor (M12-03): view every version per entity type, publish a new effective-dated version.
 *
 * @see SRS M12-03, UAT-11
 */
final class ApprovalMatrixController
{
    use HandlesDomainErrors;

    public function index(Request $request, PermissionService $permissions): Response
    {
        $user = $this->user($request);
        abort_unless($permissions->can($user, PermissionAction::View, 'approval_matrix'), 403);
        $matrices = ApprovalMatrix::query()->with('rules')->orderBy('entity_type')->orderByDesc('version')->get();

        return Inertia::render('Admin/ApprovalMatrix', [
            'matrices' => self::payload($matrices->all()),
            'roles' => ApprovalMatrixService::roleOptions(),
            'can' => ['edit' => $permissions->can($user, PermissionAction::Edit, 'approval_matrix')],
        ]);
    }

    public function store(Request $request, PermissionService $permissions, ApprovalMatrixService $service): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($permissions->can($user, PermissionAction::Edit, 'approval_matrix'), 403);
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:32'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
            'rules' => ['required', 'array', 'min:1', 'max:20'],
            'rules.*.level' => ['required', 'integer', 'min:1', 'max:5'],
            'rules.*.min_amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'rules.*.max_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'rules.*.approver_roles' => ['required', 'array', 'min:1'],
            'rules.*.approver_roles.*' => ['string', 'in:'.implode(',', ApprovalMatrixService::APPROVER_ROLES)],
        ]);

        return $this->attempt(fn () => $service->publish($data['entity_type'], array_values($data['rules']), Carbon::parse($data['effective_from'])->startOfDay(), $data['reason'], $user),
            __('New approval matrix version published.'));
    }

    /**
     * @param  list<ApprovalMatrix>  $matrices  ordered by entity type, version desc
     * @return list<array{entity_type: string, versions: list<array<string, mixed>>}>
     */
    private static function payload(array $matrices): array
    {
        $grouped = [];
        foreach ($matrices as $m) {
            $rules = [];
            foreach ($m->rules->sortBy(fn (ApprovalMatrixRule $r): string => sprintf('%02d-%020s', $r->level, (string) $r->min_amount->getAmount())) as $r) {
                $rules[] = ['level' => $r->level, 'min_amount' => (string) $r->min_amount->getAmount(), 'max_amount' => $r->max_amount === null ? null : (string) $r->max_amount->getAmount(), 'approver_roles' => $r->approver_roles];
            }
            $grouped[$m->entity_type][] = ['id' => $m->id, 'version' => $m->version, 'effective_from' => $m->effective_from->toDateString(), 'effective_to' => $m->effective_to?->toDateString(),
                'notes' => $m->notes, 'current' => $m->effective_from->lte(now()) && ($m->effective_to === null || $m->effective_to->gt(now())), 'rules' => $rules];
        }
        $out = [];
        foreach ($grouped as $type => $versions) {
            $out[] = ['entity_type' => (string) $type, 'versions' => $versions];
        }

        return $out;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
