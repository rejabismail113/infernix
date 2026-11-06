<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Notifications\BusinessNotification;
use App\Kernel\Reports\ReportCatalog;
use App\Modules\Reporting\Models\ReportSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs due report schedules. Every recipient is re-authorised at send time and the export is produced AS that
 * recipient, so a schedule can never mail someone data they could not export themselves (NFR-02).
 *
 * @see SRS M11-05, G-05
 */
final class ScheduledReportService
{
    public function __construct(private readonly ExportService $exports, private readonly ReportCatalog $catalog, private readonly PermissionService $permissions) {}

    /** @return array{schedules: int, sent: int, skipped: int} */
    public function runDue(?Carbon $now = null): array
    {
        $now ??= now();
        $stats = ['schedules' => 0, 'sent' => 0, 'skipped' => 0];
        foreach (ReportSchedule::query()->where('active', true)->get() as $s) {
            if (! $s->isDue($now)) {
                continue;
            }
            $stats['schedules']++;
            $def = $this->catalog->find($s->report_key);
            foreach (User::query()->with('roles')->whereIn('id', $s->recipient_ids)->whereNull('deactivated_at')->get() as $user) {
                if ($def === null || ! $this->permissions->can($user, PermissionAction::Export, $def->resource) || ! $this->permissions->can($user, PermissionAction::Create, 'exports')) {
                    $stats['skipped']++;

                    continue;
                }
                try {
                    $export = $this->exports->export($user, $s->report_key, $s->format, $s->filters ?? []);
                    Notification::send($user, new BusinessNotification('ScheduledReport', "Scheduled report: {$def->name}", "Your {$s->frequency} {$def->name} export is ready ({$export->row_count} rows).",
                        route('documents.download', ['document' => $export->document_id]), ['mail', 'database']));
                    $stats['sent']++;
                } catch (Throwable $e) {
                    Log::warning('Scheduled report failed', ['schedule_id' => $s->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                    $stats['skipped']++;
                }
            }
            $s->forceFill(['last_run_at' => $now])->save();
        }

        return $stats;
    }
}
