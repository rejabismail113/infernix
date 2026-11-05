<?php

declare(strict_types=1);

namespace App\Modules\Csr;

use Illuminate\Support\ServiceProvider;

/**
 * Module M08 — Csr.
 *
 * @see SRS §4 M08
 * @see docs/ARCHITECTURE.md
 */
final class CsrServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M08';

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
