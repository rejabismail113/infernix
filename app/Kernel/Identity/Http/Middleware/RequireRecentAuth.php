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

    public static function windowMinutes(): int
    {
        return (int) config('infernix.security.recent_auth_minutes', 10);
    }

    public static function isRecent(Request $request): bool
    {
        $at = $request->session()->get(self::SESSION_KEY);

        return is_string($at) && Carbon::parse($at)->gt(now()->subMinutes(self::windowMinutes()));
    }

    public static function confirm(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->toIso8601String());
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (self::isRecent($request)) {
            return $next($request);
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
