<?php

declare(strict_types=1);

namespace App\Modules\Activities\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Corporate activity / trip. Holds NO costs: finance lines point at it via activity_id (M07-02).
 *
 * @see SRS M07-01, M07-02, M07-03
 *
 * @property int $id
 * @property int $company_id
 * @property string $activity_no
 * @property string $type
 * @property string $title
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string|null $location
 * @property int|null $organizer_id
 * @property string|null $purpose
 * @property int|null $project_id
 * @property int|null $client_id
 * @property Money $budget
 * @property string $status
 * @property string|null $report
 * @property Carbon|null $report_submitted_at
 * @property int $created_by
 * @property-read Collection<int, ActivityAttendee> $attendees
 * @property-read Collection<int, ActivityItineraryItem> $itinerary
 */
final class Activity extends Model implements PermissionResource
{
    use Auditable;

    public const TYPES = ['event', 'site_visit', 'conference', 'trip', 'training_delivery', 'csr'];

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'report_submitted_at' => 'datetime', 'budget' => MoneyCast::class, 'project_id' => 'integer', 'client_id' => 'integer', 'organizer_id' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'activities';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->organizer_id === $user->id || $this->created_by === $user->id;
    }

    /** Attendee, or the activity belongs to a project the user may see (M02-07 scope). */
    public function isAssignedTo(User $user): bool
    {
        if ($this->attendees()->where('user_id', $user->id)->exists()) {
            return true;
        }
        if ($this->project_id === null) {
            return false;
        }
        $visible = app(ProjectDirectory::class)->visibleProjectIds($user);

        return $visible === '*' || in_array($this->project_id, $visible, true);
    }

    /** @return HasMany<ActivityAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(ActivityAttendee::class);
    }

    /** @return HasMany<ActivityItineraryItem, $this> */
    public function itinerary(): HasMany
    {
        return $this->hasMany(ActivityItineraryItem::class)->orderBy('item_date')->orderBy('sort');
    }
}
