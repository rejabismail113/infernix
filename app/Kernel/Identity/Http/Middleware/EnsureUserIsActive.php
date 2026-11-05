<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Middleware;

use App\Kernel\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated users and AUD users outside their window are logged out immediately (G-01, §2 AUD).
 *
 * @see SRS M12-01, §2
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && (! $user->isActive() || ! $user->withinAccessWindow())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('Your access is not active.')]);
        }

        return $next($request);
    }
}
