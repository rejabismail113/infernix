<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Identity\Enums\RoleCode;
use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Models\UserInvitation;
use App\Kernel\Identity\Services\InvitationService;
use DomainException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users: list, profile, invite, roles, deactivate. Every action is policy-checked (deny-by-default).
 *
 * @see SRS G-01, M12-01, M12-02, UAT-01
 */
final class UserController
{
    use AuthorizesRequests;

    public function __construct(private readonly InvitationService $invitations) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);
        /** @var User $actor */
        $actor = $request->user();

        $users = User::query()->with('roles')->orderBy('name')->get()
            ->filter(fn (User $u): bool => $actor->can('view', $u))
            ->map(fn (User $u): array => $this->present($u))->values();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'invitations' => $actor->can('create', User::class)
                ? UserInvitation::query()->with('role')->whereNull('accepted_at')->where('expires_at', '>', now())->get()
                    ->map(fn (UserInvitation $i): array => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role->code, 'expires_at' => $i->expires_at->toIso8601String()])
                : [],
            'roles' => Role::query()->orderBy('id')->get(['id', 'code', 'name']),
            'can' => ['create' => $actor->can('create', User::class)],
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('view', $user);
        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('Admin/Users/Show', [
            'user' => $this->present($user->load('roles')),
            'roles' => Role::query()->orderBy('id')->get(['id', 'code', 'name']),
            'can' => ['edit' => $actor->can('update', $user), 'delete' => $actor->can('delete', $user)],
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::in(array_map(fn (RoleCode $r) => $r->value, RoleCode::cases()))],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $this->invitations->invite($data['email'], Role::byCode($data['role']), $actor, $data['name'] ?? null);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return back()->with('success', __('Invitation sent.'));
    }

    public function roles(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::in(array_map(fn (RoleCode $r) => $r->value, RoleCode::cases()))],
            'reason' => ['required', 'string', 'max:500'],
            'access_from' => ['nullable', 'date'],
            'access_until' => ['nullable', 'date', 'after:access_from'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $this->invitations->setRoles($user, $data['roles'], $actor, $data['reason']);
        if (in_array(RoleCode::Auditor->value, $data['roles'], true)) {
            $user->forceFill(['access_from' => $data['access_from'] ?? null, 'access_until' => $data['access_until'] ?? null])->save();
        }

        return back()->with('success', __('Roles updated.'));
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $actor */
        $actor = $request->user();
        try {
            $this->invitations->deactivate($user, $actor, $data['reason']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', __('User deactivated.'));
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $actor */
        $actor = $request->user();
        $this->invitations->reactivate($user, $actor, $data['reason']);

        return back()->with('success', __('User reactivated.'));
    }

    /** @return array<string, mixed> */
    private function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'roles' => $u->roles->pluck('code')->values()->all(),
            'mfa_enabled' => $u->mfaEnabled(),
            'mfa_required' => $u->mfaRequired(),
            'active' => $u->isActive(),
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'access_from' => $u->access_from?->toIso8601String(),
            'access_until' => $u->access_until?->toIso8601String(),
        ];
    }
}
