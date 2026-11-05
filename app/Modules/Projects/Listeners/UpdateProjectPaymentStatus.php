<?php

declare(strict_types=1);

namespace App\Modules\Projects\Listeners;

use App\Kernel\Money\Money;
use App\Modules\Commercial\Events\ProjectBillingChanged;
use App\Modules\Projects\Models\Project;

/**
 * M02 keeps status_payment in step with M03 billing, from event data only (no cross-module query).
 *
 * @see SRS M02-01, WF-04 ("invoice can remain unpaid after project completion")
 */
final class UpdateProjectPaymentStatus
{
    public function handle(ProjectBillingChanged $event): void
    {
        $project = Project::query()->find($event->projectId);
        if ($project === null) {
            return;
        }
        $invoiced = Money::myr($event->invoiced);
        $settled = Money::myr($event->settled);
        $status = match (true) {
            $invoiced->isZero() => 'unbilled',
            $settled->isPositive() && $settled->isGreaterThanOrEqualTo($invoiced) && $invoiced->isGreaterThanOrEqualTo($project->contract_value) => 'paid',
            $settled->isPositive() => 'partially_paid',
            $invoiced->isGreaterThanOrEqualTo($project->contract_value) => 'billed',
            default => 'partially_billed',
        };
        if ($project->status_payment !== $status) {
            $project->forceFill(['status_payment' => $status])->save();
        }
    }
}
