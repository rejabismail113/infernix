<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Middleware;

use App\Kernel\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MFA enforcement: users whose role requires MFA (OWN, FIN) must enrol before using the app, and every
 * session must pass the TOTP challenge once if MFA is enabled.
 *
 * @see SRS G-01, M12-01, NFR-01
 */
final class EnsureMfa
{
    public const SESSION_KEY = 'mfa.verified_at';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }
        if ($request->routeIs('mfa.*', 'logout')) {
            return $next($request);
        }
        if ($user->mfaRequired() && ! $user->mfaEnabled()) {
            return redirect()->route('mfa.setup');
        }
        if ($user->mfaEnabled() && ! $request->session()->has(self::SESSION_KEY)) {
            return redirect()->route('mfa.challenge');
        }

        return $next($request);
    }
}
