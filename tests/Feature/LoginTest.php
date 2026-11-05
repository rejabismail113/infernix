<?php

declare(strict_types=1);

/** @see SRS G-01 — login; invitations/MFA/deactivation arrive in Prompt 1a. */
it('redirects guests from protected pages to login (deny-by-default)', function (): void {
    $this->get('/')->assertRedirect(route('login'));
})->group('G-01', 'NFR-02');

it('lets the seeded Owner log in with the configured credentials', function (): void {
    $owner = ownerUser();

    $this->post('/login', [
        'email' => $owner->email,
        'password' => config('infernix.seed_owner.password'),
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($owner);
    $this->get('/')->assertOk()->assertSee('Infernix Evolv Hub', false);
})->group('G-01');

it('rejects a wrong password without authenticating', function (): void {
    $owner = ownerUser();

    $this->from('/login')->post('/login', ['email' => $owner->email, 'password' => 'nope'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
})->group('G-01');

it('logs out and invalidates the session', function (): void {
    $this->actingAs(ownerUser())->post('/logout')->assertRedirect(route('login'));
    $this->assertGuest();
})->group('G-01');
