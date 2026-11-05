<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\Administration\Models\ClaimType;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * A claim line. Access follows its claim. clinical_note is encrypted and only ever presented to HR, OWN
 * and the claimant; FIN sees the amount and receipt (SRS §2.1).
 *
 * @see SRS M01-03, M01-04, §2.1
 *
 * @property int $id
 * @property int $claim_id
 * @property int $line_no
 * @property int $claim_type_id
 * @property Carbon $incurred_on
 * @property string $description
 * @property Money $amount
 * @property int|null $category_id
 * @property string $status
 * @property string|null $rejection_reason
 * @property string|null $origin
 * @property string|null $destination
 * @property string|null $distance_km
 * @property Money|null $rate_snapshot
 * @property int|null $rate_version_id
 * @property string|null $clinical_note
 * @property int|null $project_id
 * @property-read Claim $claim
 * @property-read ClaimType $claimType
 */
final class ClaimLine extends Model implements PermissionResource
{
    /** @var list<string> */
    protected $fillable = ['claim_id', 'line_no', 'claim_type_id', 'incurred_on', 'description', 'amount', 'category_id', 'status', 'origin', 'destination', 'distance_km', 'rate_snapshot', 'rate_version_id', 'clinical_note', 'project_id', 'activity_id'];

    /** @var list<string> */
    protected $hidden = ['clinical_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'amount' => MoneyCast::class, 'rate_snapshot' => MoneyCast::class, 'clinical_note' => 'encrypted'];
    }

    public static function resourceName(): string
    {
        return 'claims';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->claim->isOwnedBy($user);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->claim->isAssignedTo($user);
    }

    /** @return BelongsTo<Claim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /** @return BelongsTo<ClaimType, $this> */
    public function claimType(): BelongsTo
    {
        return $this->belongsTo(ClaimType::class);
    }

    /** Document-link policy hooks: a line is "updatable" (receipts may be attached) while its claim is editable. */
    public function canAttachEvidence(User $user): bool
    {
        return $this->claim->isEditableBy($user) || Gate::forUser($user)->allows('update', $this->claim);
    }
}
