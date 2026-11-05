<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-authentication prompt (password or TOTP) for sensitive actions: period lock, reveal, permission
 * changes, high-value payments. Reusable: `->middleware(RequireRecentAuth::class)`.
 *
 * @see SRS §2, M13-03, M12-04
 */
final class RequireRecentAuth
{
    public const SESSION_KEY = 'auth.recent_at';

    /** Set only when the recent confirmation used the authenticator (TOTP), not just a password. */
    public const MFA_SESSION_KEY = 'auth.recent_mfa_at';

    public static function windowMinutes(): int
    {
        return (int) config('infernix.security.recent_auth_minutes', 10);
    }

    public static function isRecent(Request $request): bool
    {
        $at = $request->session()->get(self::SESSION_KEY);

        return is_string($at) && Carbon::parse($at)->gt(now()->subMinutes(self::windowMinutes()));
    }

    public static function confirm(Request $request, bool $viaMfa = false): void
    {
        $request->session()->put(self::SESSION_KEY, now()->toIso8601String());
        if ($viaMfa) {
            $request->session()->put(self::MFA_SESSION_KEY, now()->toIso8601String());
        }
    }

    public static function isRecentMfa(Request $request): bool
    {
        $at = $request->session()->get(self::MFA_SESSION_KEY);

        return is_string($at) && Carbon::parse($at)->gt(now()->subMinutes(self::windowMinutes()));
    }

    /** `recent-auth` = password or TOTP; `recent-auth:mfa` = must have re-confirmed with the authenticator. */
    public function handle(Request $request, Closure $next, string $strength = 'any'): Response
    {
        if ($strength === 'mfa' ? self::isRecentMfa($request) : self::isRecent($request)) {
            return $next($request);
        }
        if ($strength === 'mfa') {
            $request->session()->put('reauth.require_mfa', true);
        }
        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            // Mutations are never silently replayed: client must re-authenticate then resubmit.
            return redirect()->route('reauth.show', ['intended' => $request->headers->get('referer') ?? url()->previous()])
                ->with('error', __('Please confirm your identity to continue.'));
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('reauth.show');
    }
}
