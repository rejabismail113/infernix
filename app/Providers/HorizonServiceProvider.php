<?php

declare(strict_types=1);

namespace App\Providers;

use App\Kernel\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Deny-by-default (NFR-02): active Owner-role users only, in EVERY environment (Horizon's default lets
        // anyone in when APP_ENV=local). Routes also run the `app` middleware (auth + active + MFA).
        Gate::define('viewHorizon', static fn (?User $user = null): bool => $user !== null && $user->isActive() && $user->isOwner());
        Horizon::auth(static fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));
    }
}
