<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One allocation between a bank line and a ledger line on the bank's GL account. Several rows sharing a
 * match_group form a split match; a trigger caps each side at its own amount (no double matching).
 *
 * @see SRS M01-07
 *
 * @property int $id
 * @property int $bank_transaction_id
 * @property int $journal_line_id
 * @property Money $amount
 * @property string $match_type
 * @property int $matched_by
 * @property string|null $match_group
 * @property int|null $rule_id
 */
final class BankMatch extends Model
{
    use Auditable;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['bank_transaction_id', 'journal_line_id', 'amount', 'match_type', 'matched_by', 'matched_at', 'match_group', 'rule_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'matched_at' => 'datetime'];
    }

    /** @return BelongsTo<JournalLine, $this> */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
