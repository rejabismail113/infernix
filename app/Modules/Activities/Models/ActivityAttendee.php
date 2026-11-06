<?php

declare(strict_types=1);

namespace App\Modules\Activities\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS M07-01
 *
 * @property int $id
 * @property int $activity_id
 * @property int|null $staff_profile_id
 * @property int|null $user_id
 * @property string|null $name
 * @property string|null $role
 */
final class ActivityAttendee extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];
}
