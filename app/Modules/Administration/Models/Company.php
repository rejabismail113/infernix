<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @see SRS G-03, M03-08
 *
 * @property int $id
 * @property string $name
 * @property string|null $ssm_no
 * @property array<string, string>|null $address
 * @property string|null $signatory_name
 * @property string|null $signatory_title
 * @property string $base_currency
 * @property string $timezone
 */
final class Company extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['name', 'ssm_no', 'address', 'phone', 'email', 'signatory_name', 'signatory_title', 'base_currency', 'timezone'];

    public static function resourceName(): string
    {
        return 'company';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['address' => 'array'];
    }

    /** @return HasMany<CompanyBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    public static function current(): self
    {
        return self::query()->orderBy('id')->firstOrFail();
    }
}
