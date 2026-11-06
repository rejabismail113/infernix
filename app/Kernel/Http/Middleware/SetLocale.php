<?php

declare(strict_types=1);

namespace App\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Interface language from the user's preference (or the session for guests): EN / BM (NFR-12). */
final class SetLocale
{
    public const SUPPORTED = ['en', 'ms'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->getAttribute('locale') ?? ($request->hasSession() ? $request->session()->get('locale') : null);
        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : (string) config('app.locale', 'en'));

        return $next($request);
    }
}
