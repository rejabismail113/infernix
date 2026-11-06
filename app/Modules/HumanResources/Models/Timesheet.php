<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\HumanResources\Enums\TimesheetStatus;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Timesheet for one period. Own scope = the staff member; assigned scope = PM of a project the entries are
 * booked to (who verifies it, M04-03).
 *
 * @see SRS M04-02, M04-03, UAT-07, WF-05
 *
 * @property int $id
 * @property int $company_id
 * @property int $staff_profile_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property TimesheetStatus $status
 * @property string $total_quantity
 * @property int|null $engagement_id
 * @property string|null $rate_type
 * @property Money|null $rate_snapshot
 * @property Money|null $calculated_amount
 * @property Carbon|null $submitted_at
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property int|null $calculated_by
 * @property string|null $rejection_reason
 * @property-read StaffProfile $staff
 * @property-read Collection<int, TimesheetEntry> $entries
 */
final class Timesheet extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'status' => TimesheetStatus::class, 'rate_snapshot' => MoneyCast::class,
            'calculated_amount' => MoneyCast::class, 'submitted_at' => 'datetime', 'verified_at' => 'datetime', 'calculated_at' => 'datetime',
        ];
    }

    public static function resourceName(): string
    {
        return 'timesheets';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->staff->user_id !== null && $this->staff->user_id === $user->id;
    }

    /** PM of any project this timesheet books time to. */
    public function isAssignedTo(User $user): bool
    {
        $managed = app(ProjectDirectory::class)->managedProjectIds($user);

        return $managed !== [] && $this->entries()->whereIn('project_id', $managed)->exists();
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    /** @return HasMany<TimesheetEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class)->orderBy('work_date');
    }
}
