<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Deny-by-default (CLAUDE.md rule 3, SRS M12-02). Prompt 1a replaces this with
        // PermissionService; until then every Gate/Policy check that is not explicitly
        // defined fails closed.
        Gate::before(static fn ($user, string $ability): ?bool => Gate::has($ability) ? null : false);

        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
        Date::use(Carbon::class);
    }
}
