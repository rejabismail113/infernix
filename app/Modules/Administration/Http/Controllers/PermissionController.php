<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\Permission;
use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permission matrix viewer/editor. Changes create a NEW policy_version row and end-date the old one
 * (policy history, M12-04); the change itself is audited as permission_change.
 *
 * @see SRS G-02, M12-02, M12-04
 */
final class PermissionController
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $this->authorize('viewAny', Permission::class);

        return Inertia::render('Admin/Permissions/Index', [
            'roles' => Role::query()->orderBy('id')->get(['id', 'code', 'name']),
            'permissions' => Permission::query()->effective()->orderBy('resource')->orderBy('action')->get()
                ->map(fn (Permission $p): array => ['id' => $p->id, 'role_id' => $p->role_id, 'resource' => $p->resource, 'action' => $p->action->value, 'record_scope' => $p->record_scope->value, 'policy_version' => $p->policy_version]),
            'actions' => array_map(fn (PermissionAction $a) => $a->value, PermissionAction::cases()),
            'scopes' => array_map(fn (RecordScope $s) => $s->value, RecordScope::cases()),
        ]);
    }

    public function update(Request $request, PermissionService $permissions): RedirectResponse
    {
        $this->authorize('update', Permission::class);
        $data = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'resource' => ['required', 'string', 'max:64', 'regex:/^[a-z_]+$/'],
            'action' => ['required', Rule::enum(PermissionAction::class)],
            'record_scope' => ['required', Rule::enum(RecordScope::class)],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $role = Role::query()->findOrFail($data['role_id']);
        if ($role->read_only && ! PermissionAction::from($data['action'])->isReadOnly() && $data['record_scope'] !== 'none') {
            return back()->withErrors(['action' => __('Read-only roles (AUD) may only hold view/export.')]);
        }

        DB::transaction(function () use ($data, $actor, $role): void {
            $current = Permission::query()->where('role_id', $role->id)->where('resource', $data['resource'])->where('action', $data['action'])->effective()->first();
            $version = 1;
            if ($current !== null) {
                $current->forceFill(['effective_to' => now()])->save();
                $version = $current->policy_version + 1;
            }
            $new = Permission::query()->create([
                'role_id' => $role->id, 'resource' => $data['resource'], 'action' => $data['action'], 'record_scope' => $data['record_scope'],
                'policy_version' => $version, 'effective_from' => now(), 'changed_by' => $actor->id,
            ]);
            Audit::log('permission_change', $new, null,
                $current ? ['record_scope' => $current->record_scope->value, 'policy_version' => $current->policy_version] : null,
                ['role' => $role->code, 'resource' => $data['resource'], 'action' => $data['action'], 'record_scope' => $data['record_scope'], 'policy_version' => $version],
                $data['reason'], $actor);
        });
        $permissions->forget();

        return back()->with('success', __('Permission updated.'));
    }
}
