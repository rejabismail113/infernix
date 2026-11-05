<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre;

use Illuminate\Support\ServiceProvider;

/**
 * Module M06 — DocumentCentre.
 *
 * @see SRS §4 M06
 * @see docs/ARCHITECTURE.md
 */
final class DocumentCentreServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M06';

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
