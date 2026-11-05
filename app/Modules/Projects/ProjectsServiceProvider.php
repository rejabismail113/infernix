<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use Illuminate\Support\ServiceProvider;

/**
 * Module M02 — Projects.
 *
 * @see SRS §4 M02
 * @see docs/ARCHITECTURE.md
 */
final class ProjectsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M02';

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
