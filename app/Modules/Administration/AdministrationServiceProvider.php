<?php

declare(strict_types=1);

namespace App\Modules\Administration;

use Illuminate\Support\ServiceProvider;

/**
 * Module M12 — Administration.
 *
 * @see SRS §4 M12
 * @see docs/ARCHITECTURE.md
 */
final class AdministrationServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M12';

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
