<?php

declare(strict_types=1);

namespace App\Modules\Administration\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS G-03, M03-08
 *
 * @property int $id
 * @property int $company_id
 * @property string $bank_name
 * @property string $account_no
 * @property string $account_name
 * @property int|null $gl_account_id
 * @property bool $is_default
 * @property bool $active
 */
final class CompanyBankAccount extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'bank_name', 'account_no', 'account_name', 'gl_account_id', 'is_default', 'active'];

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

    /** @var list<string> */
    protected array $auditRedact = ['account_no'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['account_no' => 'encrypted', 'is_default' => 'boolean', 'active' => 'boolean'];
    }

    /** Masked for every list/detail view; the full number is never sent to the client (M04-06 analogue). */
    public function maskedAccountNo(): string
    {
        $n = $this->account_no;

        return strlen($n) <= 4 ? '****' : str_repeat('*', strlen($n) - 4).substr($n, -4);
    }
}
