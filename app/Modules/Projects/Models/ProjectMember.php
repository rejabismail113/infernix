<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Team assignment: staff member (M04, by ID), role and the rate agreement version used. Assignment drives the
 * PM record scope (M02-07) and which projects staff may book time to (UAT-07).
 *
 * @see SRS M02-07, M04-02, UAT-07, WF-05
 *
 * @property int $id
 * @property int $project_id
 * @property int $staff_profile_id
 * @property int|null $user_id
 * @property string $role
 * @property int|null $engagement_id
 * @property Carbon $from_date
 * @property Carbon|null $to_date
 */
final class ProjectMember extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['project_id', 'staff_profile_id', 'user_id', 'role', 'engagement_id', 'from_date', 'to_date'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date'];
    }
}
