<?php

declare(strict_types=1);

namespace App\Modules\Activities;

use Illuminate\Support\ServiceProvider;

/**
 * Module M07 — Activities.
 *
 * @see SRS §4 M07
 * @see docs/ARCHITECTURE.md
 */
final class ActivitiesServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M07';

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
