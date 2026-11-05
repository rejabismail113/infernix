<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\ApprovalDelegation;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\Finance\Enums\ClaimStatus;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Staff reimbursement claim. STF sees only own claims (UAT-01); "assigned" = the user can approve the
 * current level through a delegation.
 *
 * @see SRS M01-03, M01-04, WF-01, UAT-02
 *
 * @property int $id
 * @property int $company_id
 * @property string $claim_no
 * @property int $staff_user_id
 * @property string $purpose
 * @property int|null $cost_centre_id
 * @property int|null $project_id
 * @property ClaimStatus $status
 * @property int $current_level
 * @property Money $total_amount
 * @property Money $approved_amount
 * @property Money $paid_amount
 * @property int|null $journal_id
 * @property string|null $rejection_reason
 * @property int $created_by
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property-read User $staff
 * @property-read Collection<int, ClaimLine> $lines
 * @property-read Collection<int, ClaimApproval> $approvals
 */
final class Claim extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'claim_no', 'staff_user_id', 'purpose', 'cost_centre_id', 'project_id', 'activity_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class, 'current_level' => 'integer', 'total_amount' => MoneyCast::class, 'approved_amount' => MoneyCast::class,
            'paid_amount' => MoneyCast::class, 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public static function resourceName(): string
    {
        return 'claims';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->staff_user_id === $user->id || $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        if ($this->status !== ClaimStatus::PendingApproval) {
            return false;
        }

        return ApprovalDelegation::query()->where('delegate_id', $user->id)->where('entity_type', 'claim')->activeAt()->get()
            ->contains(fn ($d): bool => $d->covers('claim', $this->total_amount));
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /** @return HasMany<ClaimLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ClaimLine::class)->orderBy('line_no');
    }

    /** @return HasMany<ClaimApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(ClaimApproval::class)->orderBy('id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function outstanding(): Money
    {
        return $this->approved_amount->minus($this->paid_amount);
    }

    public function isEditableBy(User $user): bool
    {
        return in_array($this->status, [ClaimStatus::Draft, ClaimStatus::Rejected], true) && $this->isOwnedBy($user);
    }
}
