<?php

declare(strict_types=1);

namespace App\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (NFR-01): strict CSP with a per-request nonce for the inline Ziggy / Vite
 * tags, no framing, no MIME sniffing, a tight referrer and permissions policy, HSTS over HTTPS, and no caching of
 * authenticated pages.
 *
 * @see SRS NFR-01, NFR-02
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $dev = app()->environment('local') && file_exists(public_path('hot'));
        $viteDev = $dev ? ' '.trim((string) @file_get_contents(public_path('hot'))) : '';
        $wsDev = $dev ? ' '.str_replace(['http://', 'https://'], ['ws://', 'wss://'], trim((string) @file_get_contents(public_path('hot')))) : '';
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$viteDev}",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net{$viteDev}", // Vue style bindings use inline style attributes
            "img-src 'self' data: blob:",
            "font-src 'self' data: https://fonts.bunny.net",
            "connect-src 'self'{$viteDev}{$wsDev}",
            "frame-src 'self' blob:", // in-app PDF preview of our own signed document stream
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];
        if ($request->isSecure() || app()->environment('production')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $k => $v) {
            if (! $response->headers->has($k)) {
                $response->headers->set($k, $v);
            }
        }
        if ($request->user() !== null && ! $response->headers->has('Cache-Control-Set')) {
            $cc = (string) $response->headers->get('Cache-Control');
            if (! str_contains($cc, 'no-store')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
        }
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
