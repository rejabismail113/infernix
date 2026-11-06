<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Jobs;

use App\Modules\Procurement\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Daily 90/60/30-day subscription renewal alerts → review tasks, never finance transactions. @see SRS M10-05, WF-06, UAT-12 */
final class RunSubscriptionRenewalAlerts implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(SubscriptionService $subscriptions): void
    {
        $subscriptions->runRenewalAlerts();
    }
}
