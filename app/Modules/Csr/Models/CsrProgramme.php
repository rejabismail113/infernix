<?php

declare(strict_types=1);

namespace App\Modules\Csr\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\People\Contracts\StaffDirectory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CSR programme. Its cash spend is the finance lines tagged to its M07 activity (activity_id, M08-02); in-kind
 * contributions are held separately and never added to cash (M08-03).
 *
 * @see SRS M08-01, M08-02, M08-03, M08-04
 *
 * @property int $id
 * @property int $company_id
 * @property string $programme_no
 * @property string $title
 * @property string|null $objectives
 * @property string|null $partner
 * @property string|null $sponsor
 * @property string|null $venue
 * @property Carbon $programme_date
 * @property string|null $beneficiary_group
 * @property int $target_count
 * @property int|null $actual_count
 * @property int|null $activity_id
 * @property string $status
 * @property string|null $outcome
 * @property int $created_by
 * @property-read Collection<int, CsrVolunteer> $volunteers
 * @property-read Collection<int, CsrInKindContribution> $inKind
 * @property-read Collection<int, CsrEvidence> $evidence
 */
final class CsrProgramme extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['programme_date' => 'date', 'target_count' => 'integer', 'actual_count' => 'integer', 'activity_id' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'csr_programmes';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    /** Volunteers see the programmes they volunteer on. */
    public function isAssignedTo(User $user): bool
    {
        $staffId = app(StaffDirectory::class)->idForUser($user);

        return $staffId !== null && $this->volunteers()->where('staff_profile_id', $staffId)->exists();
    }

    /** @return HasMany<CsrVolunteer, $this> */
    public function volunteers(): HasMany
    {
        return $this->hasMany(CsrVolunteer::class, 'programme_id');
    }

    /** @return HasMany<CsrInKindContribution, $this> */
    public function inKind(): HasMany
    {
        return $this->hasMany(CsrInKindContribution::class, 'programme_id');
    }

    /** @return HasMany<CsrEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(CsrEvidence::class, 'programme_id');
    }
}
