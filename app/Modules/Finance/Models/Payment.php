<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\Finance\Enums\PaymentStatus;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Outgoing payment (Payment Voucher PV-). Settles bills and/or approved claims via allocations.
 *
 * @see SRS M01-05, M03-06, UAT-02, UAT-11
 *
 * @property int $id
 * @property int $company_id
 * @property string $payment_no
 * @property Carbon $payment_date
 * @property int $bank_account_id
 * @property string $method
 * @property string|null $reference
 * @property string $payee_type
 * @property int|null $payee_id
 * @property string $payee_name
 * @property Money $amount
 * @property PaymentStatus $status
 * @property int $current_level
 * @property int|null $journal_id
 * @property int|null $voucher_document_id
 * @property string|null $memo
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property bool $owner_override
 * @property string|null $override_reason
 * @property-read BankAccount $bankAccount
 * @property-read Collection<int, PaymentAllocation> $allocations
 */
final class Payment extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'payment_no', 'payment_date', 'bank_account_id', 'method', 'reference', 'payee_type', 'payee_id', 'payee_name', 'amount', 'memo', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date', 'amount' => MoneyCast::class, 'status' => PaymentStatus::class, 'current_level' => 'integer',
            'approved_at' => 'datetime', 'posted_at' => 'datetime', 'owner_override' => 'boolean',
        ];
    }

    public static function resourceName(): string
    {
        return 'payments';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
