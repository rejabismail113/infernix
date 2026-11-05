<?php

declare(strict_types=1);

namespace App\Modules\Finance;

use Illuminate\Support\ServiceProvider;

/**
 * Module M01 — Finance.
 *
 * @see SRS §4 M01
 * @see docs/ARCHITECTURE.md
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M01';

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
