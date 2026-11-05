<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;

/** @see SRS G-01, G-06 */
it('redirects guests from protected pages to login (deny-by-default)', function (): void {
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/admin/users')->assertRedirect(route('login'));
})->group('G-01', 'NFR-02');

it('logs in the seeded Owner and audits the login', function (): void {
    $owner = ownerUser();
    $this->post('/login', ['email' => $owner->email, 'password' => config('infernix.seed_owner.password')])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($owner);
    expect(AuditEvent::query()->where('action', 'login')->where('actor_id', $owner->id)->exists())->toBeTrue();
})->group('G-01', 'G-06', 'M12-04');

it('rejects a wrong password, audits the failure, and stays guest', function (): void {
    $owner = ownerUser();
    $this->from('/login')->post('/login', ['email' => $owner->email, 'password' => 'nope'])
        ->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(AuditEvent::query()->where('action', 'login_failed')->exists())->toBeTrue();
})->group('G-01', 'G-06');

it('refuses login for a deactivated user', function (): void {
    $user = userWithRoles('STF');
    $user->forceFill(['deactivated_at' => now()])->save();
    $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
})->group('G-01', 'M12-01');

it('logs out and invalidates the session', function (): void {
    actingAsSatisfied(ownerUser())->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
})->group('G-01');
