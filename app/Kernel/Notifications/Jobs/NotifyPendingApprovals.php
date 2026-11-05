<?php

declare(strict_types=1);

namespace App\Kernel\Notifications\Jobs;

use App\Kernel\Notifications\NotificationService;
use App\Kernel\Notifications\PendingApprovalRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Daily reminder to approvers of items waiting at their level (rule ApprovalPending). @see SRS G-05 */
final class NotifyPendingApprovals implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(PendingApprovalRegistry $registry, NotificationService $notifications): void
    {
        foreach ($registry->items() as $item) {
            $notifications->notify('ApprovalPending', 0, 'approval:'.$item['key'].':'.today()->toDateString(), 'Approval needed: '.$item['title'], $item['title'].' is waiting for your approval.', $item['url'], $item['roles'], array_values(array_filter([$item['preparer_id']])));
        }
    }
}
