<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff payment in a batch (one calculated timesheet; a timesheet is paid only once — unique).
 *
 * @see SRS M04-04, M04-05
 *
 * @property int $id
 * @property int $payment_batch_id
 * @property int $staff_profile_id
 * @property int $timesheet_id
 * @property Money $amount
 * @property string $classification
 * @property bool $contractor_invoice_required
 * @property-read StaffProfile $staff
 * @property-read Timesheet $timesheet
 */
final class PaymentBatchLine extends Model implements PermissionResource
{
    /** @var list<string> */
    protected $fillable = ['payment_batch_id', 'staff_profile_id', 'timesheet_id', 'amount', 'classification', 'contractor_invoice_required'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'contractor_invoice_required' => 'boolean'];
    }

    public static function resourceName(): string
    {
        return 'payment_batches';
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

    /** @return BelongsTo<Timesheet, $this> */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }
}
