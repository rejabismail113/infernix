<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Client master (CRM). Commercial documents keep a SNAPSHOT of these fields at issue time, so later edits never
 * change an issued quotation or invoice (M03-07).
 *
 * @see SRS M02-01, M03-01
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $registration_no
 * @property string|null $tax_no
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $contact_person
 * @property array<string, mixed>|null $address
 * @property int $payment_terms_days
 * @property string $status
 * @property int $created_by
 * @property Carbon|null $archived_at
 */
final class Client extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'registration_no', 'tax_no', 'email', 'phone', 'contact_person', 'address', 'payment_terms_days', 'status', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['address' => 'array', 'payment_terms_days' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'clients';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function addressLine(): string
    {
        $a = $this->address ?? [];

        return implode(', ', array_filter([$a['line1'] ?? null, $a['line2'] ?? null, trim(($a['postcode'] ?? '').' '.($a['city'] ?? '')), $a['state'] ?? null, $a['country'] ?? null], fn ($v) => is_string($v) && $v !== ''));
    }
}
