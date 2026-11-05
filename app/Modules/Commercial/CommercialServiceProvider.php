<?php

declare(strict_types=1);

namespace App\Modules\Commercial;

use Illuminate\Support\ServiceProvider;

/**
 * Module M03 — Commercial.
 *
 * @see SRS §4 M03
 * @see docs/ARCHITECTURE.md
 */
final class CommercialServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M03';

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
