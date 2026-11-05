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
        // Deny-by-default: only the seeded Owner until roles exist (Prompt 1a switches this to OWN/FIN).
        Gate::define('viewHorizon', static fn (?User $user = null): bool => $user !== null
            && $user->email === config('infernix.seed_owner.email'));
    }
}
