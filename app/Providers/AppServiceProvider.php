<?php

declare(strict_types=1);

namespace App\Providers;

use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
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
        // Deny-by-default (CLAUDE.md rule 3, SRS M12-02, NFR-02):
        //  - inactive users and AUD users outside their window can do nothing;
        //  - abilities with no policy/definition are denied by Gate itself;
        //  - every allowed ability must come from a Policy backed by PermissionService.
        Gate::before(static function ($user, string $ability): ?bool {
            if (! $user instanceof User || ! app(PermissionService::class)->userMayAct($user)) {
                return false;
            }

            return null;
        });

        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
    }
}
