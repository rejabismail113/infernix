<?php

declare(strict_types=1);

namespace App\Kernel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request ID (NFR-10): accepts an inbound UUID X-Request-Id (from the proxy / load balancer) or generates
 * one; it is attached to every log line, every audit event and returned in the response header so a user's
 * error report can be traced end to end. Also logs a structured `request.completed` line with the duration
 * (p95 monitoring, NFR-05).
 *
 * @see SRS NFR-10, NFR-05, NFR-03
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $inbound = (string) $request->headers->get('X-Request-Id', '');
        $id = Str::isUuid($inbound) ? strtolower($inbound) : (string) Str::uuid(); // audit_events.request_id is a UUID
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);
        $started = hrtime(true);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $id);
        if (config('infernix.observability.log_requests')) {
            Log::info('request.completed', [
                'method' => $request->getMethod(), 'route' => $request->route()?->getName(), 'status' => $response->getStatusCode(),
                'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000), 'user_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        return $response;
    }
}
