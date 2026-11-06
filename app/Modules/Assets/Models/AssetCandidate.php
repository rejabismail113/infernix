<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A posted capital purchase line waiting to be registered as an asset ("Register asset" pre-filled from the
 * purchase). Created by the AssetQualifyingPurchaseRecorded listener; registering it creates NO expense.
 *
 * @see SRS M05-01, WF-02, UAT-03
 *
 * @property int $id
 * @property string $source_type
 * @property int $source_id
 * @property int $source_line_id
 * @property string $description
 * @property Money $amount
 * @property Carbon $purchase_date
 * @property string $status
 * @property string|null $dismissed_reason
 */
final class AssetCandidate extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'purchase_date' => 'date', 'source_id' => 'integer', 'source_line_id' => 'integer'];
    }
}
