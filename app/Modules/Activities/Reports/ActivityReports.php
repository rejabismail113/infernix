<?php

declare(strict_types=1);

namespace App\Modules\Activities\Reports;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\ReportTable;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Services\ActivityService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * M07 outputs: trip cost and activity register — actuals read from finance lines tagged to each activity.
 *
 * @see SRS M07 (Output / reporting), M07-03, M11-05
 */
final class ActivityReports
{
    public function __construct(private readonly ActivityService $activities) {}

    /** @return array<string, mixed> */
    public function tripCost(Carbon $from, Carbon $to, ?User $user = null): array
    {
        $rows = [];
        $total = BigDecimal::zero();
        foreach ($this->scoped($user)->whereIn('type', ['trip', 'site_visit', 'conference'])->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])->orderBy('start_date')->get() as $a) {
            $bva = $this->activities->budgetVsActual($a->id);
            $kinds = $bva['by_kind'];
            $rows[] = ['activity_no' => $a->activity_no, 'title' => $a->title, 'dates' => $a->start_date->toDateString().' – '.$a->end_date->toDateString(), 'location' => $a->location,
                'mileage' => $kinds['mileage'] ?? '0.00', 'other' => (string) BigDecimal::of($bva['actual'])->minus($kinds['mileage'] ?? '0')->toScale(2),
                'budget' => $bva['budget'], 'actual' => $bva['actual'], 'variance' => $bva['variance']];
            $total = $total->plus($bva['actual']);
        }

        return ReportTable::make('Trip cost '.$from->toDateString().' – '.$to->toDateString(), ['activity_no' => 'Activity', 'title' => 'Title', 'dates' => 'Dates', 'location' => 'Location',
            'mileage' => '#Mileage', 'other' => '#Hotels, transport, meals & other', 'budget' => '#Budget', 'actual' => '#Actual', 'variance' => '#Variance'], $rows,
            ['actual' => (string) $total->toScale(2)], ['Actuals are booked claim lines, expenses and bills tagged to the activity; nothing is copied (M07-02).']);
    }

    /** @return array<string, mixed> */
    public function register(Carbon $from, Carbon $to, ?User $user = null): array
    {
        $rows = $this->scoped($user)->withCount('attendees')->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])->orderBy('start_date')->get()
            ->map(function (Activity $a): array {
                $bva = $this->activities->budgetVsActual($a->id);

                return ['activity_no' => $a->activity_no, 'type' => $a->type, 'title' => $a->title, 'start_date' => $a->start_date->toDateString(), 'status' => $a->status,
                    'attendees' => (string) $a->attendees_count, 'budget' => $bva['budget'], 'actual' => $bva['actual'], 'report' => $a->report_submitted_at !== null ? 'yes' : 'no'];
            })->values()->all();

        return ReportTable::make('Activity register '.$from->toDateString().' – '.$to->toDateString(), ['activity_no' => 'Activity', 'type' => 'Type', 'title' => 'Title', 'start_date' => 'Start',
            'status' => 'Status', 'attendees' => 'Attendees', 'budget' => '#Budget', 'actual' => '#Actual', 'report' => 'Report filed'], $rows);
    }

    /** @return Builder<Activity> */
    private function scoped(?User $user): Builder
    {
        return $user === null ? Activity::query() : $this->activities->visibleQuery($user);
    }
}
