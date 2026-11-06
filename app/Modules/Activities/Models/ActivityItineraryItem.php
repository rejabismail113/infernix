<?php

declare(strict_types=1);

namespace App\Modules\Activities\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Planned itinerary line with an ESTIMATE only (budget); actual costs live in finance (M07-01, M07-03).
 *
 * @see SRS M07-01, M07-03
 *
 * @property int $id
 * @property int $activity_id
 * @property Carbon $item_date
 * @property string $kind
 * @property string $description
 * @property Money $estimated_cost
 * @property int $sort
 */
final class ActivityItineraryItem extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['item_date' => 'date', 'estimated_cost' => MoneyCast::class];
    }
}
