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
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
