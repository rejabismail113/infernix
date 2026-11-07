<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Numbering\Models\NumberingSequence;
use App\Kernel\Numbering\Services\NumberingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property string|null $client_code
 * @property string $client_type
 * @property string|null $client_type_other
 * @property string|null $industry
 * @property string|null $sst_no
 * @property string|null $einvoice_id_type
 * @property string|null $einvoice_id_no
 * @property-read Collection<int, ClientContact> $contacts
 */
final class Client extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'name', 'registration_no', 'tax_no', 'email', 'phone', 'contact_person', 'address', 'payment_terms_days', 'status', 'created_by',
        'client_type', 'client_type_other', 'industry', 'sst_no', 'einvoice_id_type', 'einvoice_id_no'];

    public const STATUSES = ['prospect', 'active', 'inactive'];

    /** Client types (mirrored by clients_type_check). */
    public const TYPES = ['government' => 'Government', 'glc' => 'GLC', 'private' => 'Private Company', 'individual' => 'Individual', 'ngo' => 'NGO', 'other' => 'Others'];

    /** e-Invoice (MyInvois) buyer identification types (mirrored by clients_einvoice_id_type_check). */
    public const EINVOICE_ID_TYPES = ['brn' => 'Business registration no. (BRN)', 'nric' => 'MyKad / MyTentera (NRIC)', 'passport' => 'Passport no.', 'army' => 'Army no.'];

    /** PLACEHOLDER list — edit freely; stored as text, "Others" lets the user type their own. */
    public const INDUSTRIES = ['Information Technology', 'Education & Training', 'Government / Public Sector', 'Construction', 'Engineering', 'Finance & Banking',
        'Healthcare', 'Manufacturing', 'Oil & Gas / Energy', 'Retail & Trade', 'Telecommunications', 'Transport & Logistics', 'Hospitality & Tourism',
        'Agriculture', 'Property / Real Estate', 'Professional Services', 'Non-profit', 'Others'];

    protected static function booted(): void
    {
        // Client ID (CLT-2026-001) from the gap-free numbering sequences, in the same transaction as the insert when
        // there is one. A missing year's sequence is created on the fly (master data, not a financial document).
        self::creating(function (Client $c): void {
            if ($c->client_code !== null && $c->client_code !== '') {
                return;
            }
            $allocate = function () use ($c): void {
                $year = (int) now()->timezone('Asia/Kuala_Lumpur')->format('Y');
                NumberingSequence::query()->firstOrCreate(
                    ['company_id' => $c->company_id, 'document_type' => 'CLIENT', 'fiscal_year' => $year],
                    ['prefix' => 'CLT', 'format' => '{prefix}-{year}-{seq}', 'next_number' => 1, 'padding' => 3],
                );
                $c->client_code = app(NumberingService::class)->allocate((int) $c->company_id, 'CLIENT', $year)['formatted'];
            };
            DB::transactionLevel() > 0 ? $allocate() : DB::transaction($allocate);
        });
    }

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

    /** @return HasMany<ClientContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id');
    }

    public function typeLabel(): string
    {
        return $this->client_type === 'other' && $this->client_type_other ? $this->client_type_other : (self::TYPES[$this->client_type] ?? $this->client_type);
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
