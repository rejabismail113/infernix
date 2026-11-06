<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\Finance\Enums\CapitalizationClass;
use App\Modules\Finance\Enums\InflowType;
use App\Modules\Finance\Enums\PaymentSource;
use App\Modules\Finance\Enums\TaxTreatment;
use App\Modules\Finance\Enums\TransactionStatus;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Income (inflow) or direct/owner-paid expense (outflow) register entry.
 *
 * @see SRS M01-01, M01-02, M01-09, M01-10, WF-03
 *
 * @property int $id
 * @property int $company_id
 * @property string $transaction_no
 * @property string $direction
 * @property InflowType|null $inflow_type
 * @property PaymentSource|null $payment_source
 * @property CapitalizationClass|null $capitalization_class
 * @property Carbon $transaction_date
 * @property Money $gross_amount
 * @property Money $tax_amount
 * @property string|null $payment_method
 * @property string|null $bank_reference
 * @property int|null $bank_account_id
 * @property string|null $counterparty_name
 * @property int|null $vendor_id
 * @property int|null $project_id
 * @property int|null $activity_id
 * @property TaxTreatment $tax_treatment
 * @property TransactionStatus $status
 * @property string|null $description
 * @property int|null $journal_id
 * @property int|null $reversal_journal_id
 * @property int $created_by
 * @property-read Collection<int, TransactionLine> $lines
 * @property-read BankAccount|null $bankAccount
 */
final class Transaction extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'transaction_no', 'direction', 'inflow_type', 'payment_source', 'capitalization_class', 'transaction_date', 'gross_amount', 'tax_amount', 'payment_method', 'bank_reference', 'bank_account_id', 'counterparty_name', 'vendor_id', 'project_id', 'activity_id', 'tax_treatment', 'status', 'description', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'inflow_type' => InflowType::class, 'payment_source' => PaymentSource::class, 'capitalization_class' => CapitalizationClass::class,
            'transaction_date' => 'date', 'gross_amount' => MoneyCast::class, 'tax_amount' => MoneyCast::class,
            'tax_treatment' => TaxTreatment::class, 'status' => TransactionStatus::class, 'posted_at' => 'datetime',
        ];
    }

    public static function resourceName(): string
    {
        return 'transactions';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return HasMany<TransactionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(TransactionLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function isInflow(): bool
    {
        return $this->direction === 'inflow';
    }
}
