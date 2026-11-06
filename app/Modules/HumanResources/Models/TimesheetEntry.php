<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of work against a project the staff member is ASSIGNED to (UAT-07), unless a role-based exception
 * applies (recorded on the entry).
 *
 * @see SRS M04-02, UAT-07
 *
 * @property int $id
 * @property int $timesheet_id
 * @property int $project_id
 * @property Carbon $work_date
 * @property string $quantity
 * @property string|null $description
 * @property int|null $task_id
 * @property bool $exception_granted
 * @property int|null $exception_granted_by
 */
final class TimesheetEntry extends Model
{
    /** @var list<string> */
    protected $fillable = ['timesheet_id', 'project_id', 'work_date', 'quantity', 'description', 'task_id', 'exception_granted', 'exception_granted_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['work_date' => 'date', 'exception_granted' => 'boolean'];
    }
}
