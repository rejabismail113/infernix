<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Jobs;

use App\Kernel\Notifications\NotificationService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Certifications\Models\Certification;
use App\Modules\Certifications\Models\Renewal;
use App\Modules\Certifications\Services\CertificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Daily: 90/60/30-day renewal reminders (rules CertificationExpiring / MembershipRenewalDue, PLACEHOLDER lead
 * days) to HR/Owner and the holder. Reminders never create a finance transaction (M09-02, M09-04).
 *
 * @see SRS M09-02, G-05
 */
final class NotifyRenewals implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(NotificationService $notifications, StaffDirectory $staff, CertificationService $service): void
    {
        $service->markExpired();
        Renewal::query()->where('status', 'open')->whereBetween('due_date', [today()->subDays(1)->toDateString(), today()->addDays(120)->toDateString()])->orderBy('due_date')
            ->each(function (Renewal $r) use ($notifications, $staff): void {
                $item = $r->renewable();
                if ($item === null) {
                    return;
                }
                $days = (int) today()->diffInDays($r->due_date, false);
                $holderUser = $item->staff_profile_id !== null ? ($staff->snapshot($item->staff_profile_id)['user_id'] ?? null) : null;
                [$event, $title, $url] = $item instanceof Certification
                    ? ['CertificationExpiring', "{$item->name} ({$item->holderLabel()})", route('certifications.certifications.index')]
                    : ['MembershipRenewalDue', "{$item->organisation} membership ({$item->holderLabel()})", route('certifications.certifications.index', ['tab' => 'memberships'])];
                $notifications->notifyBefore($event, max(0, $days), "renewal:{$r->id}", "{$title} expires in {$days} days", "{$title} is due for renewal on {$r->due_date->toDateString()}.", $url, null, array_values(array_filter([$holderUser])));
            });
    }
}
