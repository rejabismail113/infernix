<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Annual membership (professional body, association). annual_fee is informational: the fee actually paid is a
 * linked finance record on the renewal (M09-04).
 *
 * @see SRS M09-03, M09-04
 *
 * @property int $id
 * @property int $company_id
 * @property string $holder_type
 * @property int|null $staff_profile_id
 * @property string $organisation
 * @property string|null $membership_no
 * @property string|null $tier
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property bool $auto_renew
 * @property Money|null $annual_fee
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 */
final class Membership extends Model implements PermissionResource
{
    use Auditable;
    use HeldByStaffOrCompany;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'auto_renew' => 'boolean', 'annual_fee' => MoneyCast::class, 'staff_profile_id' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'memberships';
    }
}
