<?php

declare(strict_types=1);

namespace App\Modules\Assets\Jobs;

use App\Kernel\Notifications\NotificationService;
use App\Modules\Assets\Models\Asset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Daily: warranty expiry alerts (rule WarrantyExpiring, PLACEHOLDER 30 days). @see SRS M05-03, G-05 */
final class NotifyWarrantyExpiries implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(NotificationService $notifications): void
    {
        Asset::query()->whereNotIn('status', ['disposed', 'lost'])->whereNotNull('warranty_expires_on')
            ->whereBetween('warranty_expires_on', [today()->toDateString(), today()->addDays(120)->toDateString()])
            ->orderBy('warranty_expires_on')->each(function (Asset $a) use ($notifications): void {
                $days = (int) today()->diffInDays($a->warranty_expires_on);
                $notifications->notifyBefore('WarrantyExpiring', $days, "asset-warranty:{$a->id}:{$a->warranty_expires_on?->toDateString()}",
                    "Warranty for {$a->asset_no} ends in {$days} days", "{$a->name} ({$a->asset_no}) warranty ends on {$a->warranty_expires_on?->toDateString()}.", route('assets.assets.show', $a->id));
            });
    }
}
