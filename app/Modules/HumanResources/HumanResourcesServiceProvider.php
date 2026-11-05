<?php

declare(strict_types=1);

namespace App\Modules\HumanResources;

use Illuminate\Support\ServiceProvider;

/**
 * Module M04 — HumanResources.
 *
 * @see SRS §4 M04
 * @see docs/ARCHITECTURE.md
 */
final class HumanResourcesServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M04';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
    }
}
