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
use Illuminate\Support\Carbon;

/**
 * Lead / opportunity in the sales pipeline (new → qualified → proposal → negotiation → won | lost).
 *
 * @see SRS M02-02, WF-04
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $client_id
 * @property string|null $prospect_name
 * @property string $title
 * @property string $stage
 * @property string $engagement_type
 * @property Money $estimated_value
 * @property Carbon|null $expected_close
 * @property string|null $source
 * @property string|null $notes
 * @property int $owner_user_id
 * @property int|null $quotation_id
 * @property string|null $lost_reason
 * @property Carbon|null $closed_at
 * @property-read Client|null $client
 */
final class Lead extends Model implements PermissionResource
{
    use Auditable;

    public const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    /** @var list<string> */
    protected $fillable = ['company_id', 'client_id', 'prospect_name', 'title', 'stage', 'engagement_type', 'estimated_value', 'expected_close', 'source', 'notes', 'owner_user_id', 'lost_reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estimated_value' => MoneyCast::class, 'expected_close' => 'date', 'closed_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'leads';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_user_id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function partyName(): string
    {
        return $this->client !== null ? $this->client->name : (string) $this->prospect_name;
    }
}
