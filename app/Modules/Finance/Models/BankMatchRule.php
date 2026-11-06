<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-match rule (M01-07): applies to a bank account (or all), a direction, a date window, and optionally a
 * reference regex (capture group 1 must appear in the ledger text), a payee alias (bank description contains it)
 * and a ledger keyword. Auto-match books nothing — it only pairs a bank line with an exact-amount ledger line.
 *
 * @see SRS M01-07, WF-08
 *
 * @property int $id
 * @property string $name
 * @property int|null $bank_account_id
 * @property int $priority
 * @property string $direction
 * @property int $date_window_days
 * @property string|null $reference_pattern
 * @property string|null $payee_alias
 * @property string|null $ledger_keyword
 * @property bool $active
 * @property string|null $notes
 * @property int $created_by
 */
final class BankMatchRule extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'priority' => 'integer', 'date_window_days' => 'integer', 'bank_account_id' => 'integer'];
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
}
