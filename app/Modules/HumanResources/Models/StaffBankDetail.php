<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Restricted bank record (M04-06): every field encrypted; shown masked; full number only on an audited reveal
 * by HR / Owner / Finance (to pay).
 *
 * @see SRS M04-06, NFR-07
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property string $bank_name
 * @property string $account_no
 * @property string $account_name
 * @property string $account_last4
 * @property int $updated_by
 * @property Carbon|null $updated_at
 * @property-read StaffProfile $staff
 */
final class StaffBankDetail extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'bank_name', 'account_no', 'account_name', 'account_last4', 'updated_by'];

    /** @var list<string> */
    protected $hidden = ['account_no', 'bank_name', 'account_name'];

    /** @var list<string> */
    protected array $auditRedact = ['account_no', 'bank_name', 'account_name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['account_no' => 'encrypted', 'bank_name' => 'encrypted', 'account_name' => 'encrypted'];
    }

    public static function resourceName(): string
    {
        return 'staff_bank';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->staff->user_id !== null && $this->staff->user_id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }

    public function masked(): string
    {
        return '**** **** '.$this->account_last4;
    }
}
