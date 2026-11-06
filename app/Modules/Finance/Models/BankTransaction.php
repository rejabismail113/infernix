<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A bank statement line (imported or manual). Positive = money in, negative = money out.
 *
 * @see SRS M01-07
 *
 * @property int $id
 * @property int $bank_account_id
 * @property Carbon $txn_date
 * @property string $description
 * @property string|null $reference
 * @property Money $amount
 * @property string $fingerprint
 * @property string $status
 * @property-read BankMatch|null $match
 * @property-read Collection<int, BankMatch> $matches
 */
final class BankTransaction extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['bank_account_id', 'import_profile_id', 'import_batch_id', 'statement_document_id', 'txn_date', 'description', 'reference', 'amount', 'running_balance', 'fingerprint', 'status', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['txn_date' => 'date', 'amount' => MoneyCast::class, 'running_balance' => MoneyCast::class];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasOne<BankMatch, $this> */
    public function match(): HasOne
    {
        return $this->hasOne(BankMatch::class)->oldestOfMany();
    }

    /** @return HasMany<BankMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(BankMatch::class);
    }
}
