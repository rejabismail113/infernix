<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Models\UserInvitation;
use App\Kernel\Identity\Notifications\UserInvitedNotification;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invitation-based onboarding + deactivation.
 *
 * @see SRS G-01, M12-01
 */
final class InvitationService
{
    /** @return array{invitation: UserInvitation, url: string} */
    public function invite(string $email, Role $role, User $inviter, ?string $name = null): array
    {
        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException('A user with this email already exists.');
        }
        $token = Str::random(48);
        $invitation = DB::transaction(fn () => UserInvitation::query()->create([
            'company_id' => $inviter->company_id,
            'email' => $email,
            'name' => $name,
            'role_id' => $role->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('infernix.security.invitation_days', 7)),
            'invited_by' => $inviter->id,
        ]));
        $url = route('invitations.accept', ['token' => $token]);
        Notification::route('mail', $email)->notify(new UserInvitedNotification($url, $role->name));

        return ['invitation' => $invitation, 'url' => $url];
    }

    public function findByToken(string $token): ?UserInvitation
    {
        $inv = UserInvitation::query()->where('token_hash', hash('sha256', $token))->first();

        return $inv?->isPending() ? $inv : null;
    }

    public function accept(UserInvitation $invitation, string $name, string $password): User
    {
        if (! $invitation->isPending()) {
            throw new DomainException('Invitation is no longer valid.');
        }

        return DB::transaction(function () use ($invitation, $name, $password): User {
            $user = User::query()->create([
                'company_id' => $invitation->company_id,
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'email_verified_at' => now(),
                'invited_by' => $invitation->invited_by,
            ]);
            $user->roles()->attach($invitation->role_id, ['granted_by' => $invitation->invited_by, 'granted_at' => now()]);
            $invitation->forceFill(['accepted_at' => now()])->save();
            Audit::log('permission_change', $user, null, null, ['roles' => [$invitation->role->code]], 'invitation accepted', $user);

            return $user;
        });
    }

    public function deactivate(User $user, User $actor, string $reason): void
    {
        if ($user->id === $actor->id) {
            throw new DomainException('You cannot deactivate your own account.');
        }
        DB::transaction(function () use ($user, $actor, $reason): void {
            $user->forceFill(['deactivated_at' => now(), 'deactivated_by' => $actor->id, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            Audit::log('deactivate', $user, null, null, ['deactivated_at' => now()->toIso8601String()], $reason, $actor);
        });
    }

    public function reactivate(User $user, User $actor, string $reason): void
    {
        DB::transaction(function () use ($user, $actor, $reason): void {
            $user->forceFill(['deactivated_at' => null, 'deactivated_by' => null])->save();
            Audit::log('reactivate', $user, null, null, ['deactivated_at' => null], $reason, $actor);
        });
    }

    /** @param  list<string>  $roleCodes */
    public function setRoles(User $user, array $roleCodes, User $actor, string $reason): void
    {
        DB::transaction(function () use ($user, $roleCodes, $actor, $reason): void {
            $before = $user->roles->pluck('code')->values()->all();
            $ids = Role::query()->whereIn('code', $roleCodes)->pluck('id')->all();
            $sync = [];
            foreach ($ids as $id) {
                $sync[$id] = ['granted_by' => $actor->id, 'granted_at' => now()];
            }
            $user->roles()->sync($sync);
            $user->unsetRelation('roles');
            app(PermissionService::class)->forget($user);
            Audit::log('permission_change', $user, null, ['roles' => $before], ['roles' => array_values($roleCodes)], $reason, $actor);
        });
    }
}
