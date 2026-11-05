<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS G-01 */
final class PasswordResetController
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        // Always the same response: never reveal whether an email exists.
        Password::sendResetLink($request->only('email'));

        return back()->with('success', __('If that address is registered, a reset link has been sent.'));
    }

    public function show(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            Audit::log('password_reset', $user, null, null, null, null, $user);
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', __('Password updated. Please sign in.'))
            : back()->withErrors(['email' => __($status)]);
    }
}
