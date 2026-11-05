<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS M10-01
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $registration_no
 * @property string|null $tax_no
 * @property array<string, string>|null $contacts
 * @property string|null $bank_reference
 * @property string $status
 */
final class Vendor extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'registration_no', 'tax_no', 'contacts', 'bank_reference', 'status'];

    /** @var list<string> */
    protected $hidden = ['bank_reference'];

    /** @var list<string> */
    protected array $auditRedact = ['bank_reference'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contacts' => 'array', 'bank_reference' => 'encrypted'];
    }

    public static function resourceName(): string
    {
        return 'vendors';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    public function maskedBankReference(): ?string
    {
        $r = $this->bank_reference;

        return $r === null ? null : (strlen($r) <= 4 ? '****' : str_repeat('*', strlen($r) - 4).substr($r, -4));
    }
}
