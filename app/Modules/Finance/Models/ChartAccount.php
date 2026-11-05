<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Enums\AccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see SRS M01-06
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property string|null $subtype
 * @property string|null $system_key
 * @property int|null $parent_id
 * @property bool $is_postable
 * @property string $tax_treatment_default
 * @property bool $active
 * @property string|null $notes
 */
final class ChartAccount extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'code', 'name', 'type', 'subtype', 'system_key', 'parent_id', 'is_postable', 'tax_treatment_default', 'active', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => AccountType::class, 'is_postable' => 'boolean', 'active' => 'boolean'];
    }

    public static function resourceName(): string
    {
        return 'chart_accounts';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Debit-normal accounts (asset, expense) grow with debits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [AccountType::Asset, AccountType::Expense], true);
    }

    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }
}
