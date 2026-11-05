<?php

declare(strict_types=1);

namespace App\Modules\Procurement;

use App\Modules\Procurement\Models\Vendor;
use App\Modules\Procurement\Policies\VendorPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M10 — Procurement.
 *
 * @see SRS §4 M10
 * @see docs/ARCHITECTURE.md
 */
final class ProcurementServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M10';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(Vendor::class, VendorPolicy::class);
    }
}
