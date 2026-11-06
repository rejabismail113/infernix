<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @see SRS M02-03
 *
 * @property int $id
 * @property int $milestone_id
 * @property string $title
 * @property int|null $assignee_staff_id
 * @property Carbon|null $due_on
 * @property string $status
 */
final class Task extends Model
{
    /** @var list<string> */
    protected $fillable = ['milestone_id', 'title', 'assignee_staff_id', 'due_on', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }
}
