<?php

declare(strict_types=1);

namespace App\Kernel;

use Illuminate\Support\ServiceProvider;

/**
 * Shared kernel: Identity, Audit, Documents (contracts), Numbering, Money, Approval, Notifications.
 * Business modules (App\Modules\*) depend on the kernel; the kernel never depends on a module.
 *
 * @see docs/ARCHITECTURE.md
 */
final class KernelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Identity/Routes/web.php');
    }
}
