<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Project stub (Prompt 3). M02 (Prompt 4) adds members, milestones and training via new migrations.
 *
 * @see SRS M02-01, M03-02, WF-04, UAT-06
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property int $client_id
 * @property string $name
 * @property string $engagement_type
 * @property string|null $scope
 * @property Money $contract_value
 * @property string $status_delivery
 * @property string $status_payment
 * @property int|null $source_quotation_id
 * @property int|null $pm_user_id
 * @property int $created_by
 * @property-read Client $client
 */
final class Project extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'code', 'client_id', 'name', 'engagement_type', 'scope', 'starts_on', 'ends_on', 'contract_value', 'status_delivery', 'status_payment', 'source_quotation_id', 'pm_user_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['contract_value' => MoneyCast::class, 'starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'projects';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->pm_user_id === $user->id;
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
