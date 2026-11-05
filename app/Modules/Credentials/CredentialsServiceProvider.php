<?php

declare(strict_types=1);

namespace App\Modules\Credentials;

use Illuminate\Support\ServiceProvider;

/**
 * Module M13 — Credentials.
 *
 * @see SRS §4 M13
 * @see docs/ARCHITECTURE.md
 */
final class CredentialsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M13';

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
