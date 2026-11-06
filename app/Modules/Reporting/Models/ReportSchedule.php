<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @see SRS M11-05
 *
 * @property int $id
 * @property string $report_key
 * @property string $format
 * @property string $frequency
 * @property int|null $day
 * @property list<int> $recipient_ids
 * @property array<string, string>|null $filters
 * @property bool $active
 * @property Carbon|null $last_run_at
 * @property int $created_by
 */
final class ReportSchedule extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recipient_ids' => 'array', 'filters' => 'array', 'active' => 'boolean', 'last_run_at' => 'datetime', 'day' => 'integer'];
    }

    /** Due today? daily; weekly on ISO weekday `day` (1 = Mon); monthly on day-of-month `day` (clamped to month end). */
    public function isDue(Carbon $now): bool
    {
        if (! $this->active || ($this->last_run_at !== null && $this->last_run_at->isSameDay($now))) {
            return false;
        }

        return match ($this->frequency) {
            'daily' => true,
            'weekly' => $now->dayOfWeekIso === ($this->day ?? 1),
            default => $now->day === min($this->day ?? 1, $now->daysInMonth),
        };
    }
}
