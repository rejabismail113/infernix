<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\Administration\Models\CompanyBankAccount;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Finance view of a company bank account: its GL account and statement opening balance.
 *
 * @see SRS M01-07, G-03
 *
 * @property int $id
 * @property int $company_bank_account_id
 * @property int $gl_account_id
 * @property Money $opening_balance
 * @property Carbon|null $opening_balance_date
 * @property-read CompanyBankAccount $companyAccount
 * @property-read ChartAccount $glAccount
 */
final class BankAccount extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_bank_account_id', 'gl_account_id', 'opening_balance', 'opening_balance_date'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['opening_balance' => MoneyCast::class, 'opening_balance_date' => 'date'];
    }

    public static function resourceName(): string
    {
        return 'bank';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<CompanyBankAccount, $this> */
    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'company_bank_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'gl_account_id');
    }

    /** @return HasMany<BankTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function label(): string
    {
        return $this->companyAccount->bank_name.' '.$this->companyAccount->maskedAccountNo();
    }
}
