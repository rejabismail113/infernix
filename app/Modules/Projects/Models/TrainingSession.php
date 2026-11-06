<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Training session of a training engagement: date, trainer, venue, participants, feedback (M02-06).
 *
 * @see SRS M02-06, M03-06
 *
 * @property int $id
 * @property int $project_id
 * @property string $course_title
 * @property Carbon $session_date
 * @property int|null $trainer_staff_id
 * @property string|null $venue
 * @property string $status
 * @property string|null $feedback_score
 * @property string|null $feedback_comments
 * @property-read Project $project
 * @property-read Collection<int, TrainingParticipant> $participants
 */
final class TrainingSession extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['project_id', 'course_title', 'session_date', 'trainer_staff_id', 'venue', 'status', 'feedback_score', 'feedback_comments'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['session_date' => 'date'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<TrainingParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(TrainingParticipant::class)->orderBy('name');
    }
}
