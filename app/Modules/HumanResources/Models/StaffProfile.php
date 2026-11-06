<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Staff / part-time / freelancer / intern profile. The NRIC/passport number is encrypted at rest, hidden from
 * serialization and audit diffs, and only ever shown masked unless an authorised HR/Owner reveals it (audited).
 *
 * @see SRS M04-01, M04-05, M04-06, NFR-07
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $staff_no
 * @property string $engagement_type
 * @property string $legal_name
 * @property string|null $preferred_name
 * @property string $id_type
 * @property string|null $id_number
 * @property string|null $id_last4
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $position
 * @property Carbon|null $joined_on
 * @property Carbon|null $ended_on
 * @property string $status
 * @property string $classification
 * @property Carbon|null $classification_reviewed_at
 * @property string|null $notes
 * @property int $created_by
 * @property-read Collection<int, Engagement> $engagements
 * @property-read StaffBankDetail|null $bank
 */
final class StaffProfile extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'staff_no', 'engagement_type', 'legal_name', 'preferred_name', 'id_type', 'id_number', 'id_last4', 'email', 'phone', 'position', 'joined_on', 'ended_on', 'status', 'classification', 'notes', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['id_number'];

    /** @var list<string> */
    protected array $auditRedact = ['id_number'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['id_number' => 'encrypted', 'joined_on' => 'date', 'ended_on' => 'date', 'classification_reviewed_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'staff_profiles';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id !== null && $this->user_id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    public function maskedId(): ?string
    {
        if ($this->id_last4 === null) {
            return null;
        }

        return $this->id_type === 'nric' ? '******-**-'.$this->id_last4 : str_repeat('*', 5).$this->id_last4;
    }

    public function displayName(): string
    {
        return $this->preferred_name ?: $this->legal_name;
    }

    public function contractorInvoiceRequired(): bool
    {
        return $this->classification === 'contractor';
    }

    /** @return HasMany<Engagement, $this> */
    public function engagements(): HasMany
    {
        return $this->hasMany(Engagement::class)->orderByDesc('version');
    }

    /** @return HasOne<StaffBankDetail, $this> */
    public function bank(): HasOne
    {
        return $this->hasOne(StaffBankDetail::class);
    }

    /** Rate version in force on a date (latest version whose window covers it). */
    public function engagementOn(Carbon|string $date): ?Engagement
    {
        $d = $date instanceof Carbon ? $date->toDateString() : $date;

        return $this->engagements()->whereDate('effective_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d))->first();
    }
}
