<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $claim_id
 * @property int $level
 * @property int $approver_id
 * @property string $decision
 * @property int|null $delegation_id
 * @property bool $owner_override
 * @property string|null $reason
 * @property Carbon $decided_at
 * @property-read User $approver
 */
final class ClaimApproval extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['claim_id', 'level', 'approver_id', 'decision', 'delegation_id', 'owner_override', 'reason', 'decided_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['owner_override' => 'boolean', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
