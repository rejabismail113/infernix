<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see SRS M01-06
 *
 * @property int $id
 * @property int $journal_id
 * @property int $line_no
 * @property int $account_id
 * @property Money $debit
 * @property Money $credit
 * @property string $currency
 * @property int|null $project_id
 * @property int|null $cost_centre_id
 * @property int|null $activity_id
 * @property string|null $description
 * @property-read Journal $journal
 * @property-read ChartAccount $account
 */
final class JournalLine extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['debit' => MoneyCast::class, 'credit' => MoneyCast::class];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'account_id');
    }
}
