<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Services\ScheduledReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Daily: run due report schedules. @see SRS M11-05 */
final class SendScheduledReports implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(ScheduledReportService $service): void
    {
        $service->runDue();
    }
}
