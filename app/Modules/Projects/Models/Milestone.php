<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Deliverable / milestone with due date, completion and blocker (M02-03); optional billable amount for
 * milestone invoicing (M03-03).
 *
 * @see SRS M02-03, M03-03
 *
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property string|null $deliverable
 * @property Carbon|null $due_on
 * @property string $status
 * @property string|null $blocker
 * @property Money|null $billable_amount
 * @property int $sort_order
 * @property Carbon|null $completed_at
 * @property-read Project $project
 * @property-read Collection<int, Task> $tasks
 */
final class Milestone extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['project_id', 'title', 'deliverable', 'due_on', 'status', 'blocker', 'billable_amount', 'sort_order', 'completed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['due_on' => 'date', 'completed_at' => 'datetime', 'billable_amount' => MoneyCast::class, 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('id');
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'done' && $this->due_on !== null && $this->due_on->lt(today());
    }
}
