<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login / logout. Logins (success and failure) are audited; deactivated or out-of-window users are refused.
 *
 * @see SRS G-01, M12-01, G-06
 */
final class AuthenticatedSessionController
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $credentials['email'])->first();
        if ($user !== null && (! $user->isActive() || ! $user->withinAccessWindow())) {
            Audit::log('login_denied', $user, null, null, ['reason' => 'inactive_or_outside_window'], null, $user);
            throw ValidationException::withMessages(['email' => __('Your access is not active.')]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            Audit::log('login_failed', User::class, $user?->id, null, ['email' => $credentials['email']], null, $user);
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        $request->session()->regenerate();
        $request->session()->forget(EnsureMfa::SESSION_KEY);
        RequireRecentAuth::confirm($request);

        /** @var User $user */
        $user = $request->user();
        User::query()->whereKey($user->id)->update(['last_login_at' => now()]);
        Audit::log('login', $user, null, null, ['remember' => $request->boolean('remember')], null, $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            Audit::log('logout', $user, null, null, null, null, $user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
