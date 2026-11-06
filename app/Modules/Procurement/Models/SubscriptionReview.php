<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Renewal review task raised by the 90/60/30-day alert: open → renew | cancel. Creates no money (WF-06).
 *
 * @see SRS M10-05, WF-06, UAT-12
 *
 * @property int $id
 * @property int $subscription_id
 * @property Carbon $renewal_date
 * @property int $alert_level
 * @property string $status
 * @property string|null $decision_note
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 */
final class SubscriptionReview extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['renewal_date' => 'date', 'decided_at' => 'datetime', 'alert_level' => 'integer'];
    }
}
