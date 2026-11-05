<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use Illuminate\Support\ServiceProvider;

/**
 * Module M11 — Reporting.
 *
 * @see SRS §4 M11
 * @see docs/ARCHITECTURE.md
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M11';

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
