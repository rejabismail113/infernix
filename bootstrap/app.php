<?php

declare(strict_types=1);

use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\EnsureUserIsActive;
use App\Kernel\Identity\Http\Middleware\HandleInertiaRequests;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'mfa' => EnsureMfa::class,
            'recent-auth' => RequireRecentAuth::class,
        ]);
        // 'app' = authenticated, active (not deactivated / within AUD window), MFA satisfied.
        $middleware->group('app', ['auth', EnsureUserIsActive::class, EnsureMfa::class]);
        // Check URL signatures BEFORE route-model binding so signed download links cannot be used to
        // probe which record ids exist (403 for any tampered link, never 404).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ValidateSignature::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Inertia error pages (403 page etc.) for browser requests; JSON/API and debug 500s are untouched.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $debugServerError = $status >= 500 && config('app.debug');
            if ($request->expectsJson() || $debugServerError || ! in_array($status, [403, 404, 429, 500, 503], true)) {
                return $response;
            }
            if ($request->routeIs('documents.stream') && $status !== 403 && $status !== 404) {
                return $response;
            }

            return Inertia::render('Errors/Show', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
