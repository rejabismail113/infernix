<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Services\MfaService;

/** @see SRS G-01, M12-01, NFR-01 */
it('forces OWN and FIN to enrol in MFA before using the app', function (string $role): void {
    $user = userWithRoles($role);
    expect($user->mfaRequired())->toBeTrue();
    $this->actingAs($user)->get('/')->assertRedirect(route('mfa.setup'));
    $this->actingAs($user)->get('/admin/users')->assertRedirect(route('mfa.setup'));
})->with(['OWN', 'FIN'])->group('G-01', 'M12-01', 'NFR-01');

it('does not force MFA on roles where it is optional', function (string $role): void {
    $user = userWithRoles($role);
    expect($user->mfaRequired())->toBeFalse();
    $this->actingAs($user)->get('/')->assertOk();
})->with(['STF', 'PM', 'HR', 'APP'])->group('G-01', 'M12-01');

it('enrols with a valid TOTP code, then challenges every new session', function (): void {
    $user = userWithRoles('FIN');
    $mfa = app(MfaService::class);

    $this->actingAs($user)->get(route('mfa.setup'))->assertOk();
    $secret = $user->fresh()->mfa_secret;
    expect($secret)->not->toBeNull();

    $this->actingAs($user)->post(route('mfa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($user)->post(route('mfa.confirm'), ['code' => $mfa->currentCode($secret)])->assertOk();
    expect($user->fresh()->mfaEnabled())->toBeTrue();

    // New session without the MFA flag → challenge.
    $this->flushSession();
    $this->actingAs($user->fresh())->get('/')->assertRedirect(route('mfa.challenge'));
    $this->actingAs($user->fresh())->post(route('mfa.verify'), ['code' => $mfa->currentCode($secret)])
        ->assertRedirect(route('dashboard'))->assertSessionHas(EnsureMfa::SESSION_KEY);
})->group('G-01', 'M12-01', 'NFR-01');

it('never serialises the MFA secret or recovery codes', function (): void {
    $user = userWithRoles('FIN');
    $user->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now(), 'mfa_recovery_codes' => ['AAAAA-BBBBB']])->save();
    $json = json_encode($user->fresh());
    expect($json)->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('AAAAA-BBBBB')->not->toContain('mfa_secret');
})->group('NFR-01', 'NFR-03');
