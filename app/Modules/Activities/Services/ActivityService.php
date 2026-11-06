<?php

declare(strict_types=1);

namespace App\Modules\Activities\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ActivityDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Search\SearchIndexer;
use App\Modules\Activities\Exceptions\ActivityRejected;
use App\Modules\Activities\Models\Activity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Activities & travel (M07). Costs are never copied: finance lines are TAGGED with activity_id (one activity per
 * line), and budget vs actual reads those booked lines (M07-02, M07-03). Project margin keeps reading the
 * project-tagged ledger only, so an activity cost tagged to a project is counted there exactly once.
 *
 * @see SRS M07-01, M07-02, M07-03, M02-05
 */
final class ActivityService implements ActivityDirectory
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly FinanceRecords $finance,
        private readonly StaffDirectory $staff,
        private readonly ProjectDirectory $projects,
        private readonly PermissionService $permissions,
        private readonly SearchIndexer $search,
    ) {}

    /** @return Builder<Activity> activities the user may see (record scope applied in SQL) */
    public function visibleQuery(User $user): Builder
    {
        $scope = $this->permissions->scopeFor($user, 'activities', PermissionAction::View);
        $q = Activity::query();
        if ($scope === RecordScope::All) {
            return $q;
        }
        if ($scope === RecordScope::None) {
            return $q->whereRaw('1 = 0');
        }
        $projects = $scope === RecordScope::Assigned ? $this->projects->visibleProjectIds($user) : [];

        return $q->where(function (Builder $w) use ($user, $scope, $projects): void {
            $w->where('organizer_id', $user->id)->orWhere('created_by', $user->id);
            if ($scope === RecordScope::Assigned) {
                $w->orWhereHas('attendees', fn ($a) => $a->where('user_id', $user->id));
                if ($projects === '*') {
                    $w->orWhereNotNull('project_id');
                } elseif ($projects !== []) {
                    $w->orWhereIn('project_id', $projects);
                }
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $actor): Activity
    {
        $this->validateDates($data);

        return DB::transaction(function () use ($data, $actor): Activity {
            $a = Activity::query()->create($this->fields($data) + [
                'company_id' => $actor->company_id, 'activity_no' => $this->numbering->allocate((int) $actor->company_id, 'ACTIVITY', (int) Carbon::parse((string) $data['start_date'])->format('Y'))['formatted'],
                'status' => 'planned', 'organizer_id' => $data['organizer_id'] ?? $actor->id, 'created_by' => $actor->id,
            ]);
            $this->index($a);

            return $a;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Activity $a, array $data, User $actor): Activity
    {
        $this->validateDates($data);
        $a->forceFill($this->fields($data) + (isset($data['organizer_id']) ? ['organizer_id' => $data['organizer_id']] : []))->save();
        $this->index($a);

        return $a;
    }

    public function setStatus(Activity $a, string $status, User $actor): void
    {
        $allowed = ['planned' => ['ongoing', 'completed', 'cancelled'], 'ongoing' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => ['planned']];
        if (! in_array($status, $allowed[$a->status] ?? [], true)) {
            throw new ActivityRejected("An activity cannot move from {$a->status} to {$status}.");
        }
        $before = $a->status;
        $a->forceFill(['status' => $status])->save();
        Audit::log('status', $a, null, ['status' => $before], ['status' => $status], null, $actor);
    }

    /** @param  list<array{staff_profile_id?: int|null, name?: string|null, role?: string|null}>  $rows */
    public function saveAttendees(Activity $a, array $rows, User $actor): void
    {
        DB::transaction(function () use ($a, $rows): void {
            $a->attendees()->delete();
            $seen = [];
            foreach ($rows as $r) {
                $staffId = isset($r['staff_profile_id']) ? (int) $r['staff_profile_id'] : null;
                if ($staffId !== null) {
                    if (isset($seen[$staffId])) {
                        continue;
                    }
                    $seen[$staffId] = true;
                    $snap = $this->staff->snapshot($staffId) ?? throw new ActivityRejected('Unknown staff member.');
                    $a->attendees()->create(['staff_profile_id' => $staffId, 'user_id' => $snap['user_id'], 'name' => $snap['name'], 'role' => $r['role'] ?? null]);
                } elseif (trim((string) ($r['name'] ?? '')) !== '') {
                    $a->attendees()->create(['name' => trim((string) $r['name']), 'role' => $r['role'] ?? null]);
                }
            }
        });
        Audit::log('attendees', $a, null, null, ['count' => count($rows)], null, $actor);
    }

    /** @param  list<array{item_date: string, kind: string, description: string, estimated_cost?: string|null}>  $rows */
    public function saveItinerary(Activity $a, array $rows, User $actor): void
    {
        DB::transaction(function () use ($a, $rows): void {
            $a->itinerary()->delete();
            foreach (array_values($rows) as $i => $r) {
                $date = Carbon::parse($r['item_date']);
                if ($date->lt($a->start_date->copy()->subDays(7)) || $date->gt($a->end_date->copy()->addDays(7))) {
                    throw new ActivityRejected('Itinerary dates must be around the activity dates.');
                }
                $a->itinerary()->create(['item_date' => $date->toDateString(), 'kind' => $r['kind'], 'description' => $r['description'],
                    'estimated_cost' => Money::myr((string) (($r['estimated_cost'] ?? '') === '' ? '0' : $r['estimated_cost'])), 'sort' => $i]);
            }
        });
        Audit::log('itinerary', $a, null, null, ['items' => count($rows)], null, $actor);
    }

    public function submitReport(Activity $a, string $report, User $actor): void
    {
        if (! in_array($a->status, ['ongoing', 'completed'], true)) {
            throw new ActivityRejected('The post-event report is written once the activity has started.');
        }
        $a->forceFill(['report' => $report, 'report_submitted_at' => now()])->save();
        Audit::log('report', $a, null, null, ['length' => mb_strlen($report)], null, $actor);
    }

    /** Link (tag) a booked cost line to this activity; nothing is copied or posted (M07-02). */
    public function linkCost(Activity $a, string $lineType, int $lineId, User $actor): void
    {
        $this->finance->tagActivity($lineType, $lineId, $a->id, $actor);
    }

    public function unlinkCost(Activity $a, string $lineType, int $lineId, User $actor): void
    {
        $lines = collect($this->finance->activityLines($a->id))->where('line_type', $lineType)->where('line_id', $lineId);
        if ($lines->isEmpty()) {
            throw new ActivityRejected('That cost is not linked to this activity.');
        }
        $this->finance->tagActivity($lineType, $lineId, null, $actor);
    }

    public function snapshot(int $activityId): ?array
    {
        $a = Activity::query()->find($activityId);

        return $a === null ? null : ['id' => $a->id, 'activity_no' => $a->activity_no, 'title' => $a->title, 'type' => $a->type, 'start_date' => $a->start_date->toDateString(),
            'end_date' => $a->end_date->toDateString(), 'project_id' => $a->project_id, 'client_id' => $a->client_id, 'budget' => (string) $a->budget->getAmount(), 'status' => $a->status];
    }

    public function options(User $user): array
    {
        return $this->visibleQuery($user)->whereIn('status', ['planned', 'ongoing', 'completed'])->orderByDesc('start_date')->limit(300)->get()
            ->map(fn (Activity $a): array => ['id' => $a->id, 'label' => "{$a->activity_no} {$a->title}"])->values()->all();
    }

    public function createForCsr(string $title, string $date, ?string $location, string $budget, User $actor): int
    {
        return $this->create(['type' => 'csr', 'title' => $title, 'start_date' => $date, 'end_date' => $date, 'location' => $location, 'budget' => $budget, 'purpose' => 'CSR programme'], $actor)->id;
    }

    public function budgetVsActual(int $activityId): array
    {
        $a = Activity::query()->findOrFail($activityId);
        $actual = BigDecimal::zero();
        $byKind = [];
        foreach ($this->finance->activityLines($activityId) as $l) {
            $actual = $actual->plus($l['amount']);
            $byKind[$l['kind']] = (string) BigDecimal::of($byKind[$l['kind']] ?? '0')->plus($l['amount'])->toScale(2);
        }
        $budget = BigDecimal::of((string) $a->budget->getAmount());

        return ['budget' => (string) $budget->toScale(2), 'actual' => (string) $actual->toScale(2), 'variance' => (string) $budget->minus($actual)->toScale(2), 'by_kind' => $byKind];
    }

    public function index(Activity $a): void
    {
        $this->search->index('activity', $a->id, "{$a->activity_no} {$a->title}", trim(($a->location ?? '').' '.($a->purpose ?? '')), 'Activities');
    }

    /** @param  array<string, mixed>  $data */
    private function validateDates(array $data): void
    {
        if (isset($data['start_date'], $data['end_date']) && Carbon::parse((string) $data['end_date'])->lt(Carbon::parse((string) $data['start_date']))) {
            throw new ActivityRejected('End date is before the start date.');
        }
        if (isset($data['type']) && ! in_array($data['type'], Activity::TYPES, true)) {
            throw new ActivityRejected('Unknown activity type.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $out = array_intersect_key($data, array_flip(['type', 'title', 'start_date', 'end_date', 'location', 'purpose', 'project_id', 'client_id']));
        if (array_key_exists('project_id', $data) && $data['project_id'] !== null && ! array_key_exists('client_id', $data)) {
            $out['client_id'] = $this->projects->snapshot((int) $data['project_id'])['client_id'] ?? null;
        }
        if (array_key_exists('budget', $data)) {
            $out['budget'] = Money::myr((string) (($data['budget'] ?? '') === '' ? '0' : $data['budget']));
        }

        return $out;
    }
}
