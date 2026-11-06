<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Modules\HumanResources\Enums\TimesheetStatus;
use App\Modules\HumanResources\Exceptions\HrRejected;
use App\Modules\HumanResources\Models\Engagement;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\HumanResources\Models\Timesheet;
use App\Modules\HumanResources\Models\TimesheetEntry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Timesheets: staff book time ONLY to projects they are assigned to on that date (UAT-07) unless their role is in
 * the configurable exception list (recorded on the entry). The project PM verifies (never the staff member
 * themself); Finance calculates payment = approved quantity × the rate version in force, which is then locked
 * (WF-05).
 *
 * @see SRS M04-02, M04-03, UAT-07, WF-05
 */
final class TimesheetService
{
    public function __construct(private readonly ProjectDirectory $projects, private readonly PermissionService $permissions) {}

    public function create(StaffProfile $staff, string $periodStart, string $periodEnd, User $actor): Timesheet
    {
        $start = Carbon::parse($periodStart);
        $end = Carbon::parse($periodEnd);
        if ($end->lt($start) || $start->diffInDays($end) > 62) {
            throw new HrRejected('A timesheet period must be at most two months.');
        }
        if ($staff->status !== 'active') {
            throw new HrRejected('Inactive staff cannot submit timesheets.');
        }
        if (Timesheet::query()->where('staff_profile_id', $staff->id)->whereDate('period_start', '<=', $end)->whereDate('period_end', '>=', $start)->exists()) {
            throw new HrRejected('A timesheet already covers part of this period.');
        }
        $t = Timesheet::query()->create(['company_id' => $staff->company_id, 'staff_profile_id' => $staff->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'status' => TimesheetStatus::Draft]);
        Audit::log('create', $t, null, null, ['period' => $start->toDateString().'..'.$end->toDateString()], null, $actor);

        return $t;
    }

    /**
     * Replace the entries of a draft / rejected timesheet.
     *
     * @param  list<array{project_id: int, work_date: string, quantity: string, description?: string|null, task_id?: int|null}>  $entries
     */
    public function saveEntries(Timesheet $timesheet, array $entries, User $actor): Timesheet
    {
        return DB::transaction(function () use ($timesheet, $entries, $actor): Timesheet {
            /** @var Timesheet $t */
            $t = Timesheet::query()->lockForUpdate()->with('staff')->findOrFail($timesheet->id);
            if (! in_array($t->status, [TimesheetStatus::Draft, TimesheetStatus::Rejected], true)) {
                throw new HrRejected('Only draft or returned timesheets can be edited.');
            }
            $exceptionRole = $this->hasExceptionRole($actor);
            $total = BigDecimal::zero();
            $t->entries()->delete();
            foreach ($entries as $i => $e) {
                $date = Carbon::parse($e['work_date']);
                if ($date->lt($t->period_start) || $date->gt($t->period_end)) {
                    throw new HrRejected('Line '.($i + 1).': the date is outside the timesheet period.');
                }
                $assigned = in_array((int) $e['project_id'], $this->projects->assignedProjectIds($t->staff_profile_id, $date->toDateString()), true);
                if (! $assigned && ! $exceptionRole) {
                    throw new HrRejected('Line '.($i + 1).': you are not assigned to this project on '.$date->toDateString().' (UAT-07).');
                }
                $qty = BigDecimal::of($e['quantity']);
                if (! $qty->isPositive() || $qty->isGreaterThan(24)) {
                    throw new HrRejected('Line '.($i + 1).': quantity must be between 0 and 24.');
                }
                TimesheetEntry::query()->create([
                    'timesheet_id' => $t->id, 'project_id' => (int) $e['project_id'], 'work_date' => $date->toDateString(), 'quantity' => (string) $qty->toScale(2, RoundingMode::HALF_UP),
                    'description' => $e['description'] ?? null, 'task_id' => $e['task_id'] ?? null,
                    'exception_granted' => ! $assigned, 'exception_granted_by' => $assigned ? null : $actor->id,
                ]);
                $total = $total->plus($qty);
            }
            $t->forceFill(['total_quantity' => (string) $total->toScale(2, RoundingMode::HALF_UP)])->save();
            Audit::log('update', $t, null, null, ['entries' => count($entries), 'total' => (string) $total], null, $actor);

            return $t->refresh();
        });
    }

    public function submit(Timesheet $t, User $actor): Timesheet
    {
        TimesheetStatus::transitions()->assert('Timesheet', $t->status, TimesheetStatus::Submitted);
        if (! $t->entries()->exists()) {
            throw new HrRejected('Add at least one entry before submitting.');
        }
        $t->forceFill(['status' => TimesheetStatus::Submitted, 'submitted_at' => now(), 'rejection_reason' => null])->save();
        Audit::log('transition', $t, null, null, ['status' => 'submitted'], null, $actor);

        return $t;
    }

    /** PM verification: must manage every project booked (or hold approve:all); never one's own timesheet. */
    public function verify(Timesheet $t, User $pm, bool $approve, ?string $reason = null): Timesheet
    {
        return DB::transaction(function () use ($t, $pm, $approve, $reason): Timesheet {
            /** @var Timesheet $ts */
            $ts = Timesheet::query()->lockForUpdate()->with('staff')->findOrFail($t->id);
            if ($ts->staff->user_id === $pm->id) {
                throw new HrRejected('You cannot verify your own timesheet.');
            }
            $this->assertManagesAll($ts, $pm);
            $to = $approve ? TimesheetStatus::Verified : TimesheetStatus::Rejected;
            TimesheetStatus::transitions()->assert('Timesheet', $ts->status, $to);
            if (! $approve && trim((string) $reason) === '') {
                throw new HrRejected('Say why the timesheet is returned.');
            }
            $ts->forceFill(['status' => $to, 'verified_by' => $approve ? $pm->id : null, 'verified_at' => $approve ? now() : null, 'rejection_reason' => $approve ? null : $reason])->save();
            Audit::log($approve ? 'verify' : 'reject', $ts, null, null, ['status' => $to->value], $reason, $pm);

            return $ts;
        });
    }

    /**
     * Finance: amount = quantity × rate of the version in force at period end (fixed = the rate). The version is
     * locked and its rate snapshotted, so later rate changes never alter this amount (WF-05).
     */
    public function calculate(Timesheet $t, User $finance): Timesheet
    {
        return DB::transaction(function () use ($t, $finance): Timesheet {
            /** @var Timesheet $ts */
            $ts = Timesheet::query()->lockForUpdate()->with('staff')->findOrFail($t->id);
            TimesheetStatus::transitions()->assert('Timesheet', $ts->status, TimesheetStatus::Calculated);
            /** @var Engagement|null $eng */
            $eng = $ts->staff->engagementOn($ts->period_end);
            if ($eng === null) {
                throw new HrRejected("{$ts->staff->displayName()} has no rate agreement in force on {$ts->period_end->toDateString()}.");
            }
            Engagement::query()->whereKey($eng->id)->whereNull('locked_at')->update(['locked_at' => now()]);
            $amount = $eng->rate_type === 'fixed'
                ? $eng->rate_amount
                : BrickMoney::of(BigDecimal::of($ts->total_quantity)->multipliedBy($eng->rate_amount->getAmount())->toScale(2, RoundingMode::HALF_UP), 'MYR');
            if (! $amount->isPositive()) {
                throw new HrRejected('The calculated amount is zero.');
            }
            $ts->forceFill([
                'status' => TimesheetStatus::Calculated, 'engagement_id' => $eng->id, 'rate_type' => $eng->rate_type, 'rate_snapshot' => $eng->rate_amount,
                'calculated_amount' => $amount, 'calculated_by' => $finance->id, 'calculated_at' => now(),
            ])->save();
            Audit::log('calculate', $ts, null, null, ['engagement_version' => $eng->version, 'rate' => (string) $eng->rate_amount->getAmount(), 'amount' => Money::toDb($amount)], null, $finance);

            return $ts;
        });
    }

    /**
     * Quantity per project for cost allocation (project margin, M02-05).
     *
     * @return array<int, string> project_id => quantity
     */
    public function quantityByProject(Timesheet $t): array
    {
        return $t->entries()->reorder()->selectRaw('project_id, SUM(quantity) AS q')->groupBy('project_id')->pluck('q', 'project_id')->map(fn ($v): string => (string) $v)->all();
    }

    public function hasExceptionRole(User $user): bool
    {
        /** @var list<string> $roles */
        $roles = config('infernix.timesheets.unassigned_exception_roles', []);

        return $user->roles()->whereIn('code', $roles)->exists();
    }

    private function assertManagesAll(Timesheet $ts, User $pm): void
    {
        if ($this->permissions->scopeFor($pm, 'timesheets', PermissionAction::Approve)->value === 'all') {
            return;
        }
        $managed = $this->projects->managedProjectIds($pm);
        $booked = $ts->entries()->reorder()->distinct()->pluck('project_id')->map(fn ($v): int => (int) $v)->all();
        if ($booked === [] || array_diff($booked, $managed) !== []) {
            throw new HrRejected('Only the project manager of every project on this timesheet can verify it.');
        }
    }
}
