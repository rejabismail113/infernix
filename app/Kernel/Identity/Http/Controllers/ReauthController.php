<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\MfaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Re-authentication prompt for sensitive actions (password, or TOTP when enabled).
 *
 * @see SRS §2, M13-03
 */
final class ReauthController
{
    public function __construct(private readonly MfaService $mfa) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Auth/Reauth', [
            'mfaEnabled' => $user->mfaEnabled(),
            'mfaRequired' => (bool) $request->session()->get('reauth.require_mfa', false),
            'intended' => $request->query('intended'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string'],
            'intended' => ['nullable', 'string'],
        ]);

        $viaMfa = ($data['code'] ?? null) !== null && $user->mfaEnabled();
        $ok = $viaMfa
            ? $this->mfa->verify($user, (string) $data['code'])
            : (($data['password'] ?? null) !== null && Hash::check((string) $data['password'], $user->password));
        if ($ok && ! $viaMfa && $request->session()->get('reauth.require_mfa')) {
            throw ValidationException::withMessages(['code' => __('This action needs your authenticator code.')]);
        }

        if (! $ok) {
            Audit::log('reauth_failed', $user, null, null, null, null, $user);
            throw ValidationException::withMessages(['password' => __('Verification failed.')]);
        }

        RequireRecentAuth::confirm($request, $viaMfa);
        $request->session()->forget('reauth.require_mfa');
        Audit::log('reauth', $user, null, null, ['via' => $viaMfa ? 'mfa' : 'password'], null, $user);

        $intended = $data['intended'] ?? null;

        return $intended !== null && str_starts_with($intended, url('/'))
            ? redirect()->to($intended)
            : redirect()->intended(route('dashboard'));
    }
}
