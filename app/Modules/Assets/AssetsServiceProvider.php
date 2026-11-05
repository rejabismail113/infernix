<?php

declare(strict_types=1);

namespace App\Modules\Assets;

use Illuminate\Support\ServiceProvider;

/**
 * Module M05 — Assets.
 *
 * @see SRS §4 M05
 * @see docs/ARCHITECTURE.md
 */
final class AssetsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M05';

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
