<?php

declare(strict_types=1);

namespace App\Modules\Certifications;

use Illuminate\Support\ServiceProvider;

/**
 * Module M09 — Certifications.
 *
 * @see SRS §4 M09
 * @see docs/ARCHITECTURE.md
 */
final class CertificationsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M09';

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
