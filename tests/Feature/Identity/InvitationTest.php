<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Notifications\ResetPasswordNotification;
use App\Kernel\Identity\Notifications\UserInvitedNotification;
use App\Kernel\Identity\Services\InvitationService;
use Illuminate\Support\Facades\Notification;

/** @see SRS G-01, M12-01 */
it('invites a user by email and the invitee creates their account with the assigned role', function (): void {
    Notification::fake();
    $owner = ownerUser();

    actingAsSatisfied($owner)->post(route('admin.users.invite'), ['email' => 'new.staff@example.test', 'role' => 'STF'])
        ->assertSessionHasNoErrors();
    Notification::assertSentOnDemand(UserInvitedNotification::class);

    $url = app(InvitationService::class)->invite('second@example.test', Role::byCode('PM'), $owner)['url'];
    $token = basename(parse_url($url, PHP_URL_PATH) ?: '');

    // The invitee is a guest.
    $this->post('/logout');
    $this->flushSession();
    auth()->logout();
    $this->get($url)->assertOk();
    $this->post(route('invitations.store', ['token' => $token]), ['name' => 'Second User', 'password' => 'LongPassword123!', 'password_confirmation' => 'LongPassword123!'])
        ->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'second@example.test')->firstOrFail();
    expect($user->roles->pluck('code')->all())->toBe(['PM']);
    $this->assertAuthenticatedAs($user);
    // Token is single-use.
    $this->post('/logout');
    $this->get($url)->assertNotFound();
})->group('G-01', 'M12-01');

it('deactivates a user with an audited reason and ends their access', function (): void {
    $owner = ownerUser();
    $staff = userWithRoles('STF');

    actingAsSatisfied($owner)->post(route('admin.users.deactivate', $staff), ['reason' => 'Left the company'])->assertSessionHasNoErrors();
    expect($staff->fresh()->isActive())->toBeFalse();
    expect(AuditEvent::query()->where('action', 'deactivate')->where('entity_id', $staff->id)->where('reason', 'Left the company')->exists())->toBeTrue();

    $this->actingAs($staff->fresh())->get('/')->assertRedirect(route('login'));
})->group('G-01', 'M12-01', 'G-06');

it('resets a password via the emailed token', function (): void {
    $user = userWithRoles('STF');
    Notification::fake();
    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use ($user): bool {
        $this->post(route('password.update'), ['token' => $n->token, 'email' => $user->email, 'password' => 'BrandNewPass123', 'password_confirmation' => 'BrandNewPass123'])
            ->assertRedirect(route('login'));

        return true;
    });
    $this->post('/login', ['email' => $user->email, 'password' => 'BrandNewPass123'])->assertRedirect(route('dashboard'));
})->group('G-01');
