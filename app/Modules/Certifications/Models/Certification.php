<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Certification held by a staff member or the company; may have no expiry (M09-03).
 *
 * @see SRS M09-01, M09-02, M09-03
 *
 * @property int $id
 * @property int $company_id
 * @property string $holder_type
 * @property int|null $staff_profile_id
 * @property string $name
 * @property string $issuer
 * @property string|null $category
 * @property string|null $credential_no
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiry_date
 * @property bool $no_expiry
 * @property string|null $verification_url
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 */
final class Certification extends Model implements PermissionResource
{
    use Auditable;
    use HeldByStaffOrCompany;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date', 'no_expiry' => 'boolean', 'staff_profile_id' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'certifications';
    }

    public function dueDate(): ?Carbon
    {
        return $this->no_expiry ? null : $this->expiry_date;
    }
}
